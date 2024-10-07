<?php

namespace DTApi\Service;
use DTApi\Repository\JobRepository;

/**
 * Class UserService
 * @package DTApi\Service
 */
class JobService
{
    protected $jobRepository;
    function __construct(JobRepository $jobRepository)
    {
        $this->jobRepository = $jobRepository;
        
    }

    public function getTranslatorJobs($userId) 
    {
        return $this->jobRepository->getTranslatorJobs($userId);
    }

    public function checkParticularJob($userId, $jobItem)
    {
        return $this->jobRepository->checkParticularJob($userId, $jobItem);

    }

    public function getTranslatorJobsHistoric($userId, $pageNum)
    {
        return $this->jobRepository->checkParticularJob($userId, $pageNum);

    }

    public function findJobById($id)
    {
        return $this->jobRepository->find($id);
    }

    public function findOrFail($id)
    {
        return $this->jobRepository->findOrFail($id);
    }

    public function getJobsAssignedTranslatorDetail($job)
    {
        return $this->jobRepository->getJobsAssignedTranslatorDetail($job);
    }

    public function insertTranslatorJobRel($userId, $jobId)
    {
        return $this->jobRepository->insertTranslatorJobRel($userId, $jobId);

    }

    public function isTranslatorAlreadyBooked($jobId, $userId, $jobDue)
    {
        return $this->jobRepository->isTranslatorAlreadyBooked($jobId, $userId, $jobDue);

    }
    
    public function deleteTranslatorJobRel($translatorId, $jobId)
    {
        return $this->jobRepository->deleteTranslatorJobRel($translatorId, $jobId);

    } 

    public function getJobs($userId, $jobType, $status, $userLanguage, $gender, $translatorLevel)
    {
        return $this->jobRepository->getJobs($userId, $jobType, $status, $userLanguage, $gender, $translatorLevel);
    }

    public function assignedToPaticularTranslator($userId, $jobId)
    {
        return $this->jobRepository->assignedToPaticularTranslator($userId, $jobId);
    }

    public function checkTowns($jobUserId, $userId)
    {
        return $this->jobRepository->checkTowns($jobUserId, $userId);
    }

    public function findJobWithRelations($relations = [], $jobId)
    {
        return $this->jobRepository->findJobWithRelations($relations, $jobId);
    }

    public function findAll()
    {
        return $this->jobRepository->query();
    }

    public function create($job)
    {
        return $this->jobRepository->create($job);
    }

    public function update($id, $data)
    {
        return $this->jobRepository->update($id, $data);
    }

}