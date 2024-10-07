<?php

namespace DTApi\Repository;

use DTApi\Events\SessionEnded;

use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Helpers\Helper;
use Illuminate\Http\Request;
use DTApi\Models\UserLanguages;
use DTApi\Models\Distance;
use DTApi\Events\JobWasCanceled;
use DTApi\Service\TranslatorService;
use DTApi\Service\NotificationService;
use DTApi\Service\UserService;
use DTApi\Service\JobService;
use DTApi\Constants\Constants;
/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $logger;
    protected $userService;
    protected $translatorService;
    protected $notificationService;
    protected $jobService;

    function __construct(UserService $userService, TranslatorService $translatorService, NotificationService $notificationService, JobService $jobService)
    {
        $this->userService = $userService;
        $this->translatorService = $translatorService;
        $this->notificationService = $notificationService;
        $this->jobService = $jobService;
        $this->logger = new Logger('admin_logger');
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {

        $user = $this->userService->findUserById($user_id);
        if (!$user) {
            return ['emergencyJobs' => '', 'noramlJobs' => '', 'cuser' => '', 'usertype' => ''];
        }
        $userType = $user->is('customer') ? 'customer' : ($user->is('translator') ? 'translator' : '');
        $jobs = $this->getUsersByType($user, $userType);

        [$emergencyJobs, $normalJobs] = $this->segregateJobs($jobs, $user_id);
        return [
            'emergencyJobs' => $emergencyJobs,
            'noramlJobs' => $normalJobs,
            'cuser' => $user,
            'usertype' => $userType
        ];
    }

    public function getUsersByType($user, $userType)
    {
        if ($userType === 'customer') {
            return $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback')
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();
        }
        if ($userType === 'translator') {


            return $this->jobService->getTranslatorJobs($user->id); 
        }
        return collect();
    }

    public function segregateJobs($jobs, $user_id)
    {
        $emergencyJobs = [];
        $normalJobs = [];

        $jobs->each(function ($jobItem)  use (&$emergencyJobs, &$normalJobs) {
            if ($jobItem->immediate == 'yes') {
                $emergencyJobs[] = $jobItem;
            } else {
                $normalJobs[] = $jobItem;
            }
        });

        $normalJobs = collect($normalJobs)->each(function ($jobItem) use ($user_id) {
            $jobItem['usercheck'] = $this->jobService->checkParticularJob($user_id, $jobItem);
        })->sortBy('due')->values()->all();

        return [$emergencyJobs, $normalJobs];
    }

    /**
     * @param $user_id
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $emptyResponse = ['emergencyJobs' => [], 'noramlJobs'  => [], 'jobs' => [], 'cuser' => null, 'usertype' => '', 'numpages' => 0, 'pagenum' => 0];


        $user = $this->userService->findUserById($user_id);

        if (!$user) {
            return $emptyResponse;
        }

        $userType = $user->is('customer') ? 'customer' : ($user->is('translator') ? 'translator' : '');

        if ($userType === 'customer') 
        {
            $jobs = $user->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

            return ['emergencyJobs' => [], 'noramlJobs' => [], 'jobs' => $jobs, 'cuser' => $user, 'usertype' => 'customer', 'numpages' => 0, 'pagenum' => 0];
        }
        elseif ($userType === 'translator') {
            $pageNum = $request->get('page', 1);
            $jobs_ids = $this->jobService->getTranslatorJobsHistoric($user->id, $pageNum);
            $totalJobs = $jobs_ids->total();
            $numPages = ceil($totalJobs / 15);

            return [
                'emergencyJobs' => [],
                'noramlJobs'    => $jobs_ids,
                'jobs'          => $jobs_ids,
                'cuser'         => $user,
                'usertype'      => 'translator',
                'numpages'      => $numPages,
                'pagenum'       => $pageNum
            ];
        }
    }

    /**
     * @param $user
     * @param $data
     * @return mixed
     */
    public function store($user, $data)
    {
        if ($user->user_type != Constants::CUSTOMER_ROLE_ID) {
            return $this->failedResponse("Translator can not create booking");
        }

        $this->validateJobData($data);
        $data = $this->setJobTypeAttributes($user, $data);

        if ($data['immediate'] == 'yes') {
            $data = $this->handleImmediateJob($data);
        } else {
            $data = $this->handleRegularJob($data);
        }

        if (!empty($data['due']) && Carbon::parse($data['due'])->isPast()) {
            return $this->failedResponse("Can't create booking in past");
        }

        $data['b_created_at'] = now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = isset($data['due']) ? Helper::willExpireAt($data['due'], $data['b_created_at']) : null;

        $job = $user->jobs()->create($data);

        return $this->successResponse($job, $user, $data);
    }

    private function failedResponse($message)
    {
        return [
            'status' => 'fail',
            'message' => $message
        ];
    }

    private function validateJobData(&$data)
    {
        $requiredFields = [
            'from_language_id' => "Du måste fylla in alla fält",
            'duration' => "Du måste fylla in alla fält",
        ];

        foreach ($requiredFields as $field => $message) {
            if ($data['immediate'] == 'no' && (!isset($data[$field]) || $data[$field] == '')) {
                throw new \Exception("{$message}: {$field}");
            }
        }

        if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
            throw new \Exception("Du måste göra ett val här: customer_phone_type");
        }
    }

    private function setJobTypeAttributes($user, $data)
    {
        $consumer_type = $user->userMeta->consumer_type;

        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $data['by_admin'] = isset($data['by_admin']) ? $data['by_admin'] : 'no';

        $data['gender'] = in_array('male', $data['job_for']) ? 'male' : (in_array('female', $data['job_for']) ? 'female' : null);
        $data['certified'] = $this->checkCertifiedType($data['job_for']);

        $data['job_type'] = match ($consumer_type) {
            'rwsconsumer' => 'rws',
            'ngo' => 'unpaid',
            'paid' => 'paid',
            default => 'unknown',
        };

        return $data;
    }

    private function checkCertifiedType($jobFor)
    {
        $certified = 'normal';
        if (in_array('certified', $jobFor)) $certified = 'yes';
        if (in_array('certified_in_law', $jobFor)) $certified = 'law';
        if (in_array('certified_in_helth', $jobFor)) $certified = 'health';
        if (in_array('normal', $jobFor) && in_array('certified', $jobFor)) $certified = 'both';
        if (in_array('normal', $jobFor) && in_array('certified_in_law', $jobFor)) $certified = 'n_law';
        if (in_array('normal', $jobFor) && in_array('certified_in_helth', $jobFor)) $certified = 'n_health';

        return $certified;
    }

    private function handleImmediateJob($data)
    {
        $immediateMinutes = 5;
        $dueCarbon = Carbon::now()->addMinutes($immediateMinutes);

        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
        $data['immediate'] = 'yes';
        $data['customer_phone_type'] = 'yes';

        return $data;
    }

    private function handleRegularJob($data)
    {
        if (empty($data['due_date']) || empty($data['due_time'])) {
            throw new \Exception("Du måste fylla in alla fält: due_date or due_time");
        }

        $dueDateTime = "{$data['due_date']} {$data['due_time']}";
        $dueCarbon = Carbon::createFromFormat('m/d/Y H:i', $dueDateTime);

        $data['due'] = $dueCarbon->format('Y-m-d H:i:s');
        $data['immediate'] = 'no';

        return $data;
    }

    private function successResponse($job, $user, $data)
    {
        $response = [
            'status' => 'success',
            'id' => $job->id,
            'type' => $data['immediate'] == 'yes' ? 'immediate' : 'regular',
            'customer_physical_type' => $data['customer_physical_type'],
        ];

        $response['customer_town'] = $user->userMeta->city;
        $response['customer_type'] = $user->userMeta->customer_type;

        return $response;
    }

    /**
     * @param $id
     * @param $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = $this->jobService->findJobById($id);

        $current_translator = $job->translatorJobRel->where('cancel_at', null)->first() ??
            $job->translatorJobRel->where('completed_at', '!=', null)->first();

        $logData = [];

        $langChanged = false;

        $changeTranslator = $this->translatorService->changeTranslator($current_translator, $data, $job);

        if ($changeTranslator['translatorChanged']) $log_data[] = $changeTranslator['log_data'];

        $changeDue = $this->changeDue($job->due, $data['due']);
        if ($changeDue['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $logData[] = $changeDue['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $logData[] = [
                'old_lang' => Helper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => Helper::fetchLanguageFromJobId($data['from_language_id'])
            ];
            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $langChanged = true;
        }

        $changeStatus = $this->changeStatus($job, $data, $changeTranslator['translatorChanged']);
        if ($changeStatus['statusChanged'])
            $logData[] = $changeStatus['log_data'];

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $logData);

        $job->reference = $data['reference'];
        $job->save();

        if ($job->due <= Carbon::now()) {
            return ['Updated'];
        } else {
            if ($changeDue['dateChanged']) $this->notificationService->sendChangedDateNotification($job, $old_time);
            if ($changeTranslator['translatorChanged']) $this->notificationService->sendChangedTranslatorNotification($job, $current_translator, $changeTranslator['new_translator']);
            if ($langChanged) $this->notificationService->sendChangedLangNotification($job, $old_lang);
        }
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return array
     */
    private function changeStatus($job, $data, $changedTranslator)
    {
        $oldStatus = $job->status;

        if ($oldStatus !== $data['status']) {
            $statusChanged = match ($oldStatus) {
                'timedout' => $this->changeTimedoutStatus($job, $data, $changedTranslator),
                'completed' => $this->changeCompletedStatus($job, $data),
                'started' => $this->changeStartedStatus($job, $data),
                'pending' => $this->changePendingStatus($job, $data, $changedTranslator),
                'withdrawafter24' => $this->changeWithdrawafter24Status($job, $data),
                'assigned' => $this->changeAssignedStatus($job, $data),
                default => false,
            };

            if ($statusChanged) {
                return [
                    'statusChanged' => true,
                    'log_data' => [
                        'old_status' => $oldStatus,
                        'new_status' => $data['status']
                    ]
                ];
            }
        }
        return ['statusChanged' => false];
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        $job->created_at = $data['status'] === 'pending' ? Carbon::now() : $job->created_at;
        $job->emailsent = 0;
        $job->emailsenttovirpal = 0;

        $user = $job->user()->first();

        $emailData = $this->prepareEmailData($job, $user);
        $subject = $data['status'] === 'pending'
            ? 'Vi har nu återöppnat er bokning av ' . Helper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id
            : 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.job-change-status-to-customer');
        if ($data['status'] === 'pending') {
            $this->notificationService->sendNotificationTranslator($job, TeHelper::jobToData($job), '*');
        } elseif ($changedTranslator) {
            $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.job-accepted');
        }

        $job->save();
        return true;
    }

    private function prepareEmailData($job, $user, $session_time = null, $for_text = null)
    {
        return [
            'user' => $user,
            'job' => $job,
            'email' => !empty($job->user_email) ? $job->user_email : $user->email,
            'name' => $user->name ?? $user->name,
            'session_time' => $session_time ?? $session_time,
            'for_text'     => $for_text ?? $for_text,
        ];
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];
        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '')
                return false;
            $job->admin_comments = $data['admin_comments'];
        }
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {

        $job->status = $data['status'];
        if (empty($data['admin_comments'])) {
            return false;
        }

        $job->admin_comments = $data['admin_comments'];
        if ($data['status'] === 'completed') {
            return $this->handleCompletedJob($job, $data);
        }

        $job->save();
        return true;
    }

    private function handleCompletedJob($job, $data)
    {
        if (empty($data['sesion_time'])) {
            return false;
        }

        $interval = $data['sesion_time'];
        $job->end_at = Carbon::now();
        $job->session_time = $interval;

        $session_time = Helper::formatSessionTime($interval);

        $user = $job->user()->first();
        $userEmailData = $this->prepareEmailData($job, $user, $session_time . 'faktura');
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->notificationService->sendJobStatusEmail($userEmailData, $subject, 'emails.session-ended');

        $translatorJob = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();
        $translatorEmailData = $this->prepareEmailData($translatorJob, $translatorJob->user, $session_time, 'lön');
        $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
        $this->notificationService->sendJobStatusEmail($$translatorEmailData, $subject, 'emails.session-ended');

        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];
        if (empty($data['admin_comments'])) {
            return false;
        }
        $job->admin_comments = $data['admin_comments'];
        $user = $job->user()->first();
        $emailData = $this->prepareEmailData($job, $user);

        if ($data['status'] === 'assigned' && $changedTranslator) {
            return $this->handleJobAssigned($job, $emailData);
        }

        return $this->handleJobCancellation($job, $emailData);
    }

    private function handleJobAssigned($job, $emailData)
    {
        $job->save();
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

        $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.job-accepted');

        $translator = $this->jobService->getJobsAssignedTranslatorDetail($job);
        $user = $job->user()->first();

        $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.job-changed-translator-new-translator');

        $language = Helper::fetchLanguageFromJobId($job->from_language_id);

        $this->notificationService->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
        $this->notificationService->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

        return true;
    }

    private function handleJobCancellation($job, $emailData)
    {
        $subject = 'Avbokning av bokningsnr: #' . $job->id;
        $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.status-changed-from-pending-or-assigned-customer');
        $job->save();
        return true;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '') return false;
            $job->admin_comments = $data['admin_comments'];
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $job
     * @param $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];
            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') return false;
            $job->admin_comments = $data['admin_comments'];
            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $emailData = $this->prepareEmailData($job, $user);

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.status-changed-from-pending-or-assigned-customer');

                $translatorJob = $job->translatorJobRel->where('completed_at', null)->where('cancel_at', null)->first();

                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
                $emailData = $this->prepareEmailData($translatorJob, $translatorJob->user);

                $this->notificationService->sendJobStatusEmail($emailData, $subject, 'emails.job-cancel-translator');
            }
            $job->save();
            return true;
        }
        return false;
    }

    /**
     * @param $old_due
     * @param $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $dateChanged = false;
        if ($old_due != $new_due) {
            $log_data = [
                'old_due' => $old_due,
                'new_due' => $new_due
            ];
            $dateChanged = true;
            return ['dateChanged' => $dateChanged, 'log_data' => $log_data];
        }

        return ['dateChanged' => $dateChanged];
    }

    /**
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $job_id = $data['job_id'];
        $job = $this->jobService->findOrFail($job_id);
        $user = $job->user()->first();
        $response = ['status' => 'fail'];

        if ($job->status === 'pending' && !$this->jobService->isTranslatorAlreadyBooked($job->id, $user->id, $job->due)) {
            if ($this->jobService->insertTranslatorJobRel($user->id, $job->id)) {
                $job->status = 'assigned';
                $job->save();
                $emailData = $this->prepareEmailData($job, $user);
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->notificationService->sendAppMail($emailData, $subject, 'emails.job-accepted');
            }

            $jobs = $this->getPotentialJobs($user);
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }
        return $response;
    }

    /*Function to accept the job with the job id*/
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = $this->jobService->findOrFail($job_id);
        $response = array();

        if (!$this->jobService->isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) 
        {
            if ($job->status == 'pending' && $this->jobService->insertTranslatorJobRel($cuser->id, $job_id)) 
            {
                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->get()->first();
                $emailData = $this->prepareEmailData($job, $user);
                $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
                $this->notificationService->sendAppMail($emailData, $subject, 'emails.job-accepted');

                $data = array();
                $data['notification_type'] = 'job_accepted';
                $language = Helper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
                );
                if ($this->notificationService->isNeedToSendPush($user->id)) {
                    $users_array = array($user);
                    $this->notificationService->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->notificationService->isNeedToDelayPush($user->id));
                }
                $response['status'] = 'success';
                $response['list']['job'] = $job;
                $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
            } 
            else 
            {
                $language = Helper::fetchLanguageFromJobId($job->from_language_id);
                $response['status'] = 'fail';
                $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';
            }
        } 
        else 
        {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';
        }
        return $response;
    }

    public function cancelJob($data, $user)
    {
        $response = array();
        $job_id = $data['job_id'];
        $job = $this->jobService->findOrFail($job_id);
        $translator = $this->jobService->getJobsAssignedTranslatorDetail($job);

        if ($user->is('customer')) 
        {
            $job->withdraw_at = Carbon::now();
            if ($job->withdraw_at->diffInHours($job->due) >= 24) 
            {
                $job->status = 'withdrawbefore24';
                $response['jobstatus'] = 'success';
            } 
            else 
            {
                $job->status = 'withdrawafter24';
                $response['jobstatus'] = 'success';
            }
            $job->save();

            Event::fire(new JobWasCanceled($job));
            $response['status'] = 'success';
            $response['jobstatus'] = 'success';
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                $language = Helper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = array(
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                );
                if ($this->notificationService->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    $this->notificationService->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id)); // send Session Cancel Push to Translaotor
                }
            }
        }
        else
        {
            if ($job->due->diffInHours(Carbon::now()) > 24) 
            {
                $customer = $job->user()->get()->first();
                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';
                    $language = Helper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = array(
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    );
                    if ($this->notificationService->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);
                        $this->notificationService->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }
                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = Helper::willExpireAt($job->due, date('Y-m-d H:i:s'));
                $job->save();

                $this->jobService->deleteTranslatorJobRel($translator->id, $job_id);

                $data = Helper::jobToData($job);

                $this->notificationService->sendNotificationTranslator($job, $data, $translator->id);
                $response['status'] = 'success';
            } 
            else
            {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }
        return $response;
    }

    /*Function to get the potential jobs for paid,rws,unpaid translators*/
    public function getPotentialJobs($user)
    {
        $userMeta = $user->userMeta;
        $jobType = 'unpaid';

        $translatorType = $userMeta->translator_type;
        if ($translatorType == 'professional')
            $jobType = 'paid';   /*show all jobs for professionals.*/
        else if ($translatorType == 'rwstranslator')
            $jobType = 'rws';  /* for rwstranslator only show rws jobs. */
        
        $languages = UserLanguages::where('user_id', '=', $user->id)->get();
        $userLanguage = collect($languages)->pluck('lang_id')->all();
        $gender = $userMeta->gender;
        $translatorLevel = $userMeta->translator_level;

        /*Call the town function for checking if the job physical, then translators in one town can get job*/
        $jobIds = $this->jobService->getJobs($user->id, $jobType, 'pending', $userLanguage, $gender, $translatorLevel);
        
        foreach ($jobIds as $k => $job) 
        {
            $jobUserId = $job->user_id;
            $job->specific_job = $this->jobService->assignedToPaticularTranslator($user->id, $job->id);
            $job->check_particular_job = $this->jobService->checkParticularJob($user->id, $job);
            $checkTown = $this->jobService->checkTowns($jobUserId, $user->id);

            if ($job->specific_job == 'SpecificJob')
                if ($job->check_particular_job == 'userCanNotAcceptJob')
                    unset($jobIds[$k]);

            if (($job->customer_phone_type == 'no' || $job->customer_phone_type == '') && $job->customer_physical_type == 'yes' && $checkTown == false) 
                unset($jobIds[$k]);
        }
        return $jobIds;
    }

    public function endJob($data)
    {
        $completedDate = Carbon::now();
        $jobId = $data["job_id"];
        $jobDetail = $this->jobService->findJobWithRelations(['translatorJobRel'], $jobId);

        if ($jobDetail->status != 'started')
            return ['status' => 'success'];

        $start = date_create($$jobDetail->due);
        $end = date_create($completedDate);
        $diff = date_diff($end, $start);
        $interval = $diff->h . ':' . $diff->i . ':' . $diff->s;
       
        $jobDetail->end_at = Carbon::now();
        $jobDetail->status = 'completed';
        $jobDetail->session_time = $interval;

        $user = $jobDetail->user()->get()->first();
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;
        $session_explode = explode(':', $jobDetail->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $emailData = $this->prepareEmailData($jobDetail, $user, $session_time, 'faktura');
        $this->notificationService->sendAppMail($emailData, $subject, 'emails.session-ended');
        $jobDetail->save();

        $translator = $jobDetail->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($jobDetail, ($data['user_id'] == $jobDetail->user_id) ? $translator->user_id : $jobDetail->user_id));

        $user = $translator->user()->first();
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $jobDetail->id;

        $emailData = $this->prepareEmailData($jobDetail, $user, $session_time, 'lön');
        $this->notificationService->sendAppMail($emailData, $subject, 'emails.session-ended');

        $translator->completed_at = $completedDate;
        $translator->completed_by = $data['user_id'];
        $translator->save();

        $response['status'] = 'success';
        return $response;
    }


    public function customerNotCall($post_data)
    {
        $completedDate = Carbon::now();
        $jobId = $post_data["job_id"];
        $jobDetail = $this->jobService->findJobWithRelations(['translatorJobRel'], $jobId);

        $jobDetail->end_at = date('Y-m-d H:i:s');
        $jobDetail->status = 'not_carried_out_customer';

        $translator = $jobDetail->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $translator->completed_at = $completedDate;
        $translator->completed_by = $translator->user_id;

        $jobDetail->save();
        $translator->save();
        $response['status'] = 'success';
        return $response;
    }

    public function getAll(Request $request, $limit = null)
    {
        $user = $request->__authenticatedUser;
        $requestData = $request->all();
        if (!$user) return collect();

        if ($user->user_type == Constants::SUPERADMIN_ROLE_ID) {
            $allJobs = $this->prepareSuperAdminJobQuery($requestData);
        } else {
            $allJobs = $this->prepareUserJobsQuery($requestData, $user);
        }

        return $this->completeJobsQuery($allJobs, $requestData, $limit);
    }

    private function prepareSuperAdminJobQuery($requestData)
    {
        $allJobs = $this->jobService->findAll();

        $this->applyCommonFilters($allJobs, $requestData);

        if (isset($requestData['customer_email']) && count($requestData['customer_email']) && $requestData['customer_email'] != '') {
            $users = $this->userService->getUserByEmails($requestData['customer_email']);
            if ($users) {
                $allJobs->whereIn('user_id', collect($users)->pluck('id')->all());
            }
        }
        if (isset($requestData['translator_email']) && count($requestData['translator_email'])) {
            $jobIDs = $this->userService->getTranslatorJobIdsByEmails($requestData['translator_email']);
            if ($users) {
                $allJobs->whereIn('id', $jobIDs);
            }
        }

        if (isset($requestData['physical'])) {
            $allJobs->where('customer_physical_type', $requestData['physical'])->where('ignore_physical', 0);
        }

        if (isset($requestData['phone'])) {
            $allJobs->where('customer_phone_type', $requestData['phone']);
            if (isset($requestData['physical']))
                $allJobs->where('ignore_physical_phone', 0);
        }

        if (isset($requestData['flagged'])) {
            $allJobs->where('flagged', $requestData['flagged'])->where('ignore_flagged', 0);
        }

        if (isset($requestData['distance']) && $requestData['distance'] == 'empty') {
            $allJobs->whereDoesntHave('distance');
        }

        if (isset($requestData['salary']) &&  $requestData['salary'] == 'yes') {
            $allJobs->whereDoesntHave('user.salaries');
        }

        if (isset($requestData['consumer_type']) && $requestData['consumer_type'] != '') {
            $allJobs->whereHas('user.userMeta', function ($q) use ($requestData) {
                $q->where('consumer_type', $requestData['consumer_type']);
            });
        }

        if (isset($requestData['booking_type'])) {
            if ($requestData['booking_type'] == 'physical')
                $allJobs->where('customer_physical_type', 'yes');
            if ($requestData['booking_type'] == 'phone')
                $allJobs->where('customer_phone_type', 'yes');
        }
        return $allJobs;
    }

    private function prepareUserJobsQuery($requestData, $user)
    {
        $allJobs = $this->jobService->findAll();
        if ($user->consumer_type == 'RWS') {
            $allJobs->where('job_type', '=', 'rws');
        } else {
            $allJobs->where('job_type', '=', 'unpaid');
        }

        $this->applyCommonFilters($allJobs, $requestData);

        if (isset($requestdata['customer_email']) && $requestdata['customer_email'] != '') {
            $user = $this->userService->getUserByEmail($requestdata['customer_email']);
            if ($user) {
                $allJobs->where('user_id', '=', $user->id);
            }
        }

        return $allJobs;
    }

    private function isFeedbackRequested($requestData)
    {
        return isset($requestData['feedback']) && $requestData['feedback'] != 'false';
    }

    private function applyCommonFilters(&$query, $requestData)
    {
        if ($this->isFeedbackRequested($requestData)) {
            $query->where('ignore_feedback', '0')
                ->whereHas('feedback', function ($query) {
                    $query->where('rating', '<=', '3');
                });
        }
        if (isset($requestData['id']) && $requestData['id'] != '') {
            $query->whereIn('id', is_array($requestData['id']) ? $requestData['id'] : [$requestData['id']]);
        }

        if (isset($requestData['lang']) && $requestData['lang'] != '') {
            $query->whereIn('from_language_id', $requestData['lang']);
        }

        if (isset($requestData['status']) && $requestData['status'] != '') {
            $query->whereIn('status', $requestData['status']);
        }

        if (isset($requestData['job_type']) && $requestData['job_type'] != '') {
            $query->whereIn('job_type', $requestData['job_type']);
        }

        if (isset($requestData['filter_timetype'])) {
            $this->applyTimeFilters($query, $requestData);
        }
    }

    private function applyTimeFilters(&$query, $requestData)
    {
        $timeType = $requestData['filter_timetype'];

        if (isset($requestData['from']) && $requestData['from'] != "") {
            $query->where($timeType == "created" ? 'created_at' : 'due', '>=', $requestData["from"]);
        }

        if (isset($requestData['to']) && $requestData['to'] != "") {
            $to = $requestData["to"] . " 23:59:00";
            $query->where($timeType == "created" ? 'created_at' : 'due', '<=', $to);
        }

        $query->orderBy($timeType == "created" ? 'created_at' : 'due', 'desc');
    }

    public function completeJobsQuery($allJobs, $requestData, $limit)
    {
        $allJobs->with(['user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance']);
        if (isset($requestData['count']) && $requestData['count'] == 'true') {
            return ['count' => $allJobs->count()];
        }
        return $limit == 'all' ? $allJobs->get() : $allJobs->paginate(15);
    }

    public function reopen($request)
    {
        $jobId = $request['jobid'];
        $userId = $request['userid'];

        $job = $this->jobService->findJobById($jobId)->toArray();

        $data = array();
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = Helper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userId;
        $data['job_id'] = $jobId;
        $data['cancel_at'] = Carbon::now();

        $dataReopen = array();
        $dataReopen['status'] = 'pending';
        $dataReopen['created_at'] = Carbon::now();
        $dataReopen['will_expire_at'] = Helper::willExpireAt($job['due'], $dataReopen['created_at']);
   
        if ($job['status'] != 'timedout') 
        {
            $affectedRows = $this->jobService->update($jobId, $dataReopen);
            $newJobId = $jobId;
        } 
        else 
        {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = Helper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $jobId;
            $affectedRows = $this->jobService->create($job);
            $newJobId = $affectedRows['id'];
        }

        $this->translatorService->createTranslator($jobId, $data);
        $this->translatorService->createTranslator($data);

        if (isset($affectedRows)) 
        {
            $this->notificationService->sendNotificationByAdminCancelJob($newJobId);
            return ["Tolk cancelled!"];
        }
        return ["Please try again!"];
    }

    public function updateDistance($jobId, $distance, $time)
    {
        return Distance::where('job_id', $jobId)->update(['distance' => $distance, 'time' => $time]);
    }
}