
<?php

namespace DTApi\Service;
use DTApi\Repository\UserRepository;

class UserSevice
{
    protected $userRepository;
    function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
        
    }
    public function getUserByEmails(array $emails) 
    {
        return $this->userRepository->getUserByEmails($emails);
    }

    public function getUserByEmail(string $email)
    {
        return $this->userRepository->getUserByEmail($email);
    }

    public function getTranslatorJobIdsByEmails(array $emails)
    {
        return $this->userRepository->getTranslatorJobIdsByEmails($emails);

    }

    public function findUserById($userId)
    {
        return $this->userRepository->find($userId);
    }

    public function findUserLanguageByUserId($userId)
    {
        return $this->userRepository->findUserLanguageByUserId($userId);
    }

    public function findUserMetaByUserId($userId)
    {
        return $this->userRepository->findUserMetaByUserId($userId);
    }
}