<?php

namespace DTApi\Service;
use DTApi\Repository\BookingRepository;

class BookingService
{
    protected $bookingRepository;
    protected $jobService;

    public function __construct(BookingRepository $bookingRepository, JobService $jobService)
    {
        $this->bookingRepository = $bookingRepository;
        $this->jobService = $jobService;
    }

    public function findBookingByJobId($jobId)
    {
        return $this->bookingRepository->find($jobId);
    }

    public function getUsersBookings($user_id)
    {
        return $this->bookingRepository->getUsersJobs($user_id);
    }

    public function getAll($user)
    {
        return $this->bookingRepository->getAll($user);
    }

    public function createBooking($user, $data)
    {
        return $this->bookingRepository->store($user, $data);
    }

    public function updateBooking($id, $data)
    {
        return $this->bookingRepository->updateJob($id, $data->all(), $data->__authenticatedUser);
    }

    public function getHistory($user_id, $request)
    {
        return $this->bookingRepository->getUsersJobsHistory($user_id, $request);
    }

    public function acceptBooking($data, $user)
    {
        return $this->bookingRepository->acceptJob($data, $user);
    }

    public function acceptBookingWithId($id, $user)
    {
        return $this->bookingRepository->acceptJobWithId($id, $user);
    }

    public function cancelBooking($data, $user)
    {
        return $this->bookingRepository->cancelJob($data, $user);
    }

    public function endBooking($data)
    {
        return $this->bookingRepository->endJob($data);
    }

    public function customerNotCall($data)
    {
        return $this->bookingRepository->customerNotCall($data);
    }

    public function getPotentialJobBookings($user)
    {
        return $this->bookingRepository->getPotentialJobs($user);
    }

    public function distanceFeed($data)
    {
        $jobId = $data['jobid'];
        $distance = $data['distance'] ?? '';
        $time = $data['time'] ?? '';

        $this->bookingRepository->updateDistance($jobId, $distance, $time);

        $details = [
            'admin_comments' => $data['admincomment'] ?? '',
            'flagged' => $data['flagged'] === 'true' ? 'yes' : 'no',
            'manually_handled' => $data['manually_handled'] === 'true' ? 'yes' : 'no',
            'by_admin' => $data['by_admin'] === 'true' ? 'yes' : 'no',
            'session_time' => $data['session_time'] ?? ''
        ];

        return $this->jobService->update($jobId, $details);
    }

    public function reopenBooking($data)
    {
        return $this->bookingRepository->reopen($data);
    }
}