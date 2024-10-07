<?php

namespace DTApi\Service;

use DTApi\Repository\TranslatorRepository;
use DTApi\Repository\UserRepository;
use Carbon\Carbon;

class TranslatorService
{
    protected $userRepository;
    protected $translatorRepository;

    function __construct(UserRepository $userRepository, TranslatorRepository $translatorRepository)
    {
        $this->userRepository = $userRepository;
        $this->translatorRepository = $translatorRepository;
    }


    public function changeTranslator($currentTranslator, $data, $job)
    {

        $translatorChanged = false;
        $logData = [];

        if ($this->shouldChangeTranslator($currentTranslator, $data)) {
            $newTranslatorId = $this->resolveTranslatorId($data);

            if ($currentTranslator) {
                $newTranslator = $this->replaceCurrentTranslator($currentTranslator, $newTranslatorId, $logData);
            } else {
                $newTranslator = $this->assignNewTranslator($newTranslatorId, $job, $logData);
            }

            $translatorChanged = true;
        }

        return [
            'translatorChanged' => $translatorChanged,
            'new_translator' => $newTranslator ?? null,
            'log_data' => $logData
        ];
    }

    private function shouldChangeTranslator($currentTranslator, $data)
    {
        return !is_null($currentTranslator) ||
            (isset($data['translator']) && $data['translator'] != 0) ||
            !empty($data['translator_email']);
    }

    private function resolveTranslatorId($data)
    {
        if (!empty($data['translator_email'])) {
            return $this->userRepository->getUserByEmail($data['translator_email'])->id;
        }
        return $data['translator'] ?? null;
    }

    private function replaceCurrentTranslator($currentTranslator, $newTranslatorId, &$logData)
    {
        if ($currentTranslator->user_id == $newTranslatorId) {
            return $currentTranslator;
        }

        $newTranslator = $currentTranslator->replicate();
        $newTranslator->user_id = $newTranslatorId;
        $newTranslator->save();

        $currentTranslator->cancel_at = Carbon::now();
        $currentTranslator->save();

        $logData[] = [
            'old_translator' => $currentTranslator->user->email,
            'new_translator' => $newTranslator->user->email
        ];

        return $newTranslator;
    }

    private function assignNewTranslator($translatorId, $job, &$logData)
    {
        $newTranslator = $this->createTranslator($translatorId, $job->id);

        $logData[] = [
            'old_translator' => null,
            'new_translator' => $newTranslator->user->email
        ];

        return $newTranslator;
    }

    public function createTranslator($data)
    {
        return $this->translatorRepository->create($data);
    }

    public function updateTranslator($jobId, $data)
    {
        return $this->translatorRepository->updateTranslator ($jobId, $data);
    }
}
