<?php

namespace DTApi\Http\Controllers;

use Illuminate\Http\Request;
use DTApi\Service\NotificationService;
use DTApi\Service\JobService;
use DTApi\Helpers\Helper;

/**
 * Class NotificationController
 * @package DTApi\Http\Controllers
 */
class NotificationController extends Controller
{
    protected $notificationService;
    protected $jobService;

    function __construct(NotificationService $notificationService, JobService $jobService)
    {
       $this->notificationService = $notificationService;
       $this->jobService = $jobService;
    }

    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->jobService->findJobById($data['jobid']);
        $job_data = Helper::jobToData($job);
        $this->notificationService->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }
    
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->jobService->findJobById($data['jobid']);
        try 
        {
            $this->notificationService->sendSMSNotificationToTranslator($job);
            return response(['success' => 'SMS sent']);
        } 
        catch (\Exception $e) {
            return response(['success' => $e->getMessage()]);
        }
    }

    public function immediateJobEmail(Request $request)
    {
        $data = $request->all();
        $response = $this->notificationService->storeJobEmail($data);

        return response($response);
    }
}
