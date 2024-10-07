<?php

namespace DTApi\Http\Controllers;
use Illuminate\Http\Request;
use DTApi\Service\BookingService;
use DTApi\Service\JobService;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{

    protected $bookingService;
    protected $jobService;

    public function __construct(BookingService $bookingService, JobService $jobService)
    {
        $this->bookingService = $bookingService;
        $this->jobService = $jobService;
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function index(Request $request)
    {
        $user_id = $request->get('user_id');
        $response = $user_id ? $this->bookingService->getUsersBookings($user_id) : $this->bookingService->getAll($request);
        return response($response);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function show($id)
    {
        $job = $this->jobService->findJobWithRelations('translatorJobRel.user', $id);
        return response($job);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function store(Request $request)
    {
        $response = $this->bookingService->createBooking($request->__authenticatedUser, $request->all());
        return response($response);

    }

    /**
     * @param $id
     * @param Request $request
     * @return mixed
     */
    public function update($id, Request $request)
    {
        $response = $this->bookingService->updateBooking($id, $request);
        return response($response);
    }
    /**
     * @param Request $request
     * @return mixed
     */
    public function getHistory(Request $request)
    {
        $response = $this->bookingService->getHistory($request->get('user_id'), $request);
        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function acceptJob(Request $request)
    {
        $response = $this->bookingService->acceptBooking($request->all(), $request->__authenticatedUser);
        return response($response);
    }

    public function acceptJobWithId(Request $request)
    {
        $response = $this->bookingService->acceptBookingWithId($request->get('job_id'), $request->__authenticatedUser);
        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function cancelJob(Request $request)
    {
        $response = $this->bookingService->cancelBooking($request->all(), $request->__authenticatedUser);
        return response($response);
    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function endJob(Request $request)
    {
        $response = $this->bookingService->endBooking($request->all());
        return response($response);

    }

    public function customerNotCall(Request $request)
    {
        $response = $this->bookingService->customerNotCall($request->all());
        return response($response);

    }

    /**
     * @param Request $request
     * @return mixed
     */
    public function getPotentialJobs(Request $request)
    {
        $response = $this->bookingService->getPotentialJobBookings($request->__authenticatedUser);
        return response($response);
    }

    public function distanceFeed(Request $request)
    {
        $this->bookingService->distanceFeed($request->all());
        return response('Record updated!');
    }

    public function reopen(Request $request)
    {
        $response = $this->bookingService->reopenBooking($request->all());
        return response($response);
    }
}
