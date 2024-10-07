
<?php

namespace DTApi\Service;

use DTApi\Constants\Constants;
use Monolog\Logger;
use DTApi\Mailers\MailerInterface;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\FirePHPHandler;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Helpers\Helper;
use DTApi\Mailers\AppMailer;
use DTApi\Helpers\SendSMSHelper;
use Illuminate\Support\Facades\Log;

class NotificationService
{

    protected $mailer;
    protected $bookingRepository;
    protected $logger;
    protected $appMailer;
    protected $jobService;
    protected $userService;

    function __construct(MailerInterface $mailer, AppMailer $appMailer, JobService $jobService, UserService $userService)
    {
        $this->mailer = $mailer;
        $this->appMailer = $appMailer;
        $this->logger = new Logger('admin_logger');
        $this->jobService = $jobService;
        $this->userService = $userService;

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = $this->jobService->getJobsAssignedTranslatorDetail($job);
        $data = [
            'user'     => $translator,
            'job'      => $job,
            'old_time' => $old_time
        ];
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendChangedTranslatorNotification($job, $current_translator, $new_translator)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        $data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);
        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();
        if (!empty($job->user_email)) {
            $email = $job->user_email;
        } else {
            $email = $user->email;
        }
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        $data = [
            'user'     => $user,
            'job'      => $job,
            'old_lang' => $old_lang
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);
        $translator = $this->jobService->getJobsAssignedTranslatorDetail($job);
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    public function sendJobStatusEmail(array $emailData, string $subject, string $template)
    {
        $email = $emailData['email'] ?? null;
        $name = $emailData['name'] ?? '';

        if ($email) {
            $this->mailer->send($email, $name, $subject, $template, $emailData);
        }
    }

    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::where('user_type', '2')->where('status', '1')->where('id', '<>', $exclude_user_id)->get();
        $translator_array = [];
        $delayed_translator_array = [];

        foreach ($users as $user) {
            if (!$this->isEligibleTranslator($user, $data)) {
                continue;
            }

            $this->categorizeTranslatorsForJob($user, $job, $translator_array, $delayed_translator_array);
        }

        $this->sendAndLogNotifications($translator_array, $delayed_translator_array, $job, $data);
    }

    private function isEligibleTranslator($user, $data)
    {
        $not_get_emergency = Helper::getUsermeta($user->id, 'not_get_emergency');
        return $this->isNeedToSendPush($user->id) && !($data['immediate'] == 'yes' && $not_get_emergency == 'yes');
    }

    private function categorizeTranslatorsForJob($user, $job, &$translator_array, &$delayed_translator_array)
    {
        $potential_jobs = $this->getPotentialJobIdsWithUserId($user->id);

        foreach ($potential_jobs as $potential_job) {
            if ($job->id == $potential_job->id && $this->isTranslatorSuitableForJob($user->id, $potential_job)) {
                if ($this->isNeedToDelayPush($user->id)) {
                    $delayed_translator_array[] = $user;
                } else {
                    $translator_array[] = $user;
                }
            }
        }
    }

    private function isTranslatorSuitableForJob($userId, $job)
    {
        $job_for_translator = $this->jobService->assignedToPaticularTranslator($userId, $job->id);
        $job_checker = $this->jobService->checkParticularJob($userId, $job);

        return $job_for_translator == 'SpecificJob' && $job_checker != 'userCanNotAcceptJob';
    }

    private function sendAndLogNotifications($translator_array, $delayed_translator_array, $job, $data)
    {
        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_text = Helper::createMessageText($data);

        // Log the notification details
        $this->logPushNotification($job, $translator_array, $delayed_translator_array, $msg_text, $data);

        // Send push notifications to suitable translators
        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);
        $this->sendPushNotificationToSpecificUsers($delayed_translator_array, $job->id, $data, $msg_text, true);
    }

    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = Helper::getUsermeta($user_id, 'not_get_notification');
        if ($not_get_notification == 'yes') return false;
        return true;
    }

    private function logPushNotification($job, $translator_array, $delayed_translator_array, $msg_text, $data)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->info('Push sent for job ' . $job->id, [$translator_array, $delayed_translator_array, $msg_text, $data]);
    }


    private function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = $this->userService->findUserMetaByUserId($user_id)->first();
        $job_type = Helper::getJobTypeForTranslator($user_meta->translator_type);

        $user_languages = $this->userService->findUserLanguageByUserId($user_id)->pluck('lang_id')->toArray();
        $job_ids = $this->jobService->getJobs($user_id, $job_type, 'pending', $user_languages, $user_meta->gender, $user_meta->translator_level);

        $filtered_jobs = $this->filterJobsBasedOnTown($job_ids, $user_id);

        return Helper::convertJobIdsInObjs($filtered_jobs);
    }

    private function filterJobsBasedOnTown($job_ids, $user_id)
    {
        return array_filter($job_ids, function ($job) use ($user_id) {
            $job_details = $this->jobService->findJobById($job->id);
            $is_phone_type_unspecified = empty($job_details->customer_phone_type) || $job_details->customer_phone_type === 'no';
            $requires_physical_presence = $job_details->customer_physical_type === 'yes';

            return !($is_phone_type_unspecified && $requires_physical_presence && !$this->jobService->checkTowns($job_details->user_id, $user_id));
        });
    }

    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');
        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $onesignalAppID = Helper::getOneSignalAppID();

        $user_tags = Helper::getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $sounds = $this->determineNotificationSounds($data);

        $fields = $this->prepareNotificationFields($onesignalAppID, $user_tags, $data, $msg_text, $sounds);

        if ($is_need_delay) {
            $fields['send_after'] = DateTimeHelper::getNextBusinessTimeString();
        }

        $this->sendNotification($fields, $job_id);
    }

    private function determineNotificationSounds($data)
    {
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] === 'suitable_job') {
            if ($data['immediate'] === 'no') {
                $android_sound = 'normal_booking';
                $ios_sound = 'normal_booking.mp3';
            } else {
                $android_sound = 'emergency_booking';
                $ios_sound = 'emergency_booking.mp3';
            }
        }

        return compact('ios_sound', 'android_sound');
    }

    private function prepareNotificationFields($onesignalAppID, $user_tags, $data, $msg_text, $sounds)
    {
        return [
            'app_id'         => $onesignalAppID,
            'tags'           => json_decode($user_tags),
            'data'           => $data,
            'title'          => ['en' => 'DigitalTolk'],
            'contents'       => $msg_text,
            'ios_badgeType'  => 'Increase',
            'ios_badgeCount' => 1,
            'android_sound'  => $sounds['android_sound'],
            'ios_sound'      => $sounds['ios_sound'],
        ];
    }

    private function sendNotification($fields, $job_id)
    {
        $fields = json_encode($fields);
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', Helper::getOneSignalAuthKey()]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $logger = new Logger('push_logger');
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);

        curl_close($ch);
    }

    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) return false;
        $not_get_nighttime = Helper::getUsermeta($user_id, 'not_get_nighttime');
        if ($not_get_nighttime == 'yes') return true;
        return false;
    }

    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
        $data = array();
        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);
        if ($job->customer_physical_type == 'yes')
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );
        else
            $msg_text = array(
                "en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'
            );

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }
    public function sendAppMail(array $emailData, string $subject, string $template)
    {
        $email = $emailData['email'] ?? null;
        $name = $emailData['name'] ?? '';

        if ($email) {
            $this->mailer->send($email, $name, $subject, $template, $emailData);
        }
    }

     /**
     * Sends SMS to translators and retuns count of translators
     * @param $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $jobPosterMeta = $this->userService->findUserMetaByUserId($job->user_id)->first();

        // prepare message templates
        $date = date('d.m.Y', strtotime($job->due));
        $time = date('H:i', strtotime($job->due));
        $duration = Helper::convertToHoursMins($job->duration);
        $jobId = $job->id;
        $city = $job->city ? $job->city : $jobPosterMeta->city;

        $phoneJobMessageTemplate = trans('sms.phone_job', ['date' => $date, 'time' => $time, 'duration' => $duration, 'jobId' => $jobId]);

        $physicalJobMessageTemplate = trans('sms.physical_job', ['date' => $date, 'time' => $time, 'town' => $city, 'duration' => $duration, 'jobId' => $jobId]);

        $message = '';
        // analyse weather it's phone or physical; if both = default to phone
        if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'no') 
            // It's a physical job
            $message = $physicalJobMessageTemplate;
        else if ($job->customer_physical_type == 'no' && $job->customer_phone_type == 'yes')
            // It's a phone job
            $message = $phoneJobMessageTemplate;
        else if ($job->customer_physical_type == 'yes' && $job->customer_phone_type == 'yes')
            // It's both, but should be handled as phone job
            $message = $phoneJobMessageTemplate;
        Log::info($message);

        // send messages via sms handler
        foreach ($translators as $translator) {
            // send message to translator
            $status = SendSMSHelper::send(Constants::SMS_NUMBER, $translator->mobile, $message);
            Log::info('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * @param Job $job
     * @return mixed
     */
    public function getPotentialTranslators(Job $job)
    {

        $jobType = $job->job_type;

        if ($jobType == 'paid')
            $translatorType = 'professional';
        else if ($jobType == 'rws')
            $translatorType = 'rwstranslator';
        else if ($jobType == 'unpaid')
            $translatorType = 'volunteer';

        $jobLanguage = $job->from_language_id;
        $gender = $job->gender;

        $translatorLevel = [];
        if (empty($job->certified)) 
            return [];

        if ($job->certified == 'yes' || $job->certified == 'both') 
        {
            $translatorLevel[] = 'Certified';
            $translatorLevel[] = 'Certified with specialisation in law';
            $translatorLevel[] = 'Certified with specialisation in health care';
        } 
        elseif ($job->certified == 'law' || $job->certified == 'n_law') 
            $translatorLevel[] = 'Certified with specialisation in law';
        elseif ($job->certified == 'health' || $job->certified == 'n_health')
            $translatorLevel[] = 'Certified with specialisation in health care';
        else if ($job->certified == 'normal' || $job->certified == 'both') 
        {
            $translatorLevel[] = 'Layman';
            $translatorLevel[] = 'Read Translation courses';
        } 
        elseif (!$job->certified) 
        {
            $translatorLevel[] = 'Certified';
            $translatorLevel[] = 'Certified with specialisation in law';
            $translatorLevel[] = 'Certified with specialisation in health care';
            $translatorLevel[] = 'Layman';
            $translatorLevel[] = 'Read Translation courses';
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translatorsId = collect($blacklist)->pluck('translator_id')->all();
        $users = User::getPotentialUsers($translatorType, $jobLanguage, $gender, $translatorLevel, $translatorsId);
        return $users;
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param $job_id
     */
    public function sendNotificationByAdminCancelJob($jobId)
    {
        $job = $this->jobService->findOrFail($jobId);
        $userMeta = $job->user->userMeta()->first();
        $data = array();            // save job's information to data for sending Push
        
        $data['job_id'] = $job->id;
        $data['from_language_id'] = $job->from_language_id;
        $data['immediate'] = $job->immediate;
        $data['duration'] = $job->duration;
        $data['status'] = $job->status;
        $data['gender'] = $job->gender;
        $data['certified'] = $job->certified;
        $data['due'] = $job->due;
        $data['job_type'] = $job->job_type;
        $data['customer_phone_type'] = $job->customer_phone_type;
        $data['customer_physical_type'] = $job->customer_physical_type;
        $data['customer_town'] = $userMeta->city;
        $data['customer_type'] = $userMeta->customer_type;

        $dueDate = explode(" ", $job->due)[0];
        $data['due_date'] = $dueDate;
        $data['due_time'] = $dueDate[1];
        $data['job_for'] = array();

        if ($job->gender)
            $data['job_for'][] = $job->gender == 'male' ? 'Man' : 'Kvinna';
 
        if ($job->certified) {
            if ($job->certified == 'both')
            {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            }
            else if ($job->certified == 'yes')
                $data['job_for'][] = 'certified';
            else
                $data['job_for'][] = $job->certified;
        }
        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

     /**
     * @param $data
     * @return mixed
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];
        $job = $this->jobService->findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = isset($data['reference']) ? $data['reference'] : '';
        $user = $job->user()->get()->first();
        if (isset($data['address'])) {
            $job->address = ($data['address'] != '') ? $data['address'] : $user->userMeta->address;
            $job->instructions = ($data['instructions'] != '') ? $data['instructions'] : $user->userMeta->instructions;
            $job->town = ($data['town'] != '') ? $data['town'] : $user->userMeta->city;
        }
        $job->save();

        if (!empty($job->user_email)) {
            $email = $job->user_email;
            $name = $user->name;
        } else {
            $email = $user->email;
            $name = $user->name;
        }
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;
        $send_data = [
            'user' => $user,
            'job'  => $job
        ];
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        $data = Helper::jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));
        return $response;
    }
}