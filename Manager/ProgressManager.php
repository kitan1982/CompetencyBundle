<?php

namespace HeVinci\CompetencyBundle\Manager;

use Claroline\CoreBundle\Entity\Activity\Evaluation;
use Claroline\CoreBundle\Entity\User;
use Claroline\CoreBundle\Persistence\ObjectManager;
use HeVinci\CompetencyBundle\Entity\Ability;
use HeVinci\CompetencyBundle\Entity\Competency;
use HeVinci\CompetencyBundle\Entity\Progress\AbilityProgress;
use HeVinci\CompetencyBundle\Entity\Progress\CompetencyProgress;
use JMS\DiExtraBundle\Annotation as DI;

/**
 * @DI\Service("hevinci.competency.progress_manager")
 */
class ProgressManager
{
    private $om;
    private $abilityRepo;
    private $competencyRepo;
    private $competencyAbilityRepo;
    private $abilityProgressRepo;
    private $competencyProgressRepo;
    private $cachedProgresses = [];

    /**
     * @DI\InjectParams({
     *     "om" = @DI\Inject("claroline.persistence.object_manager")
     * })
     *
     * @param ObjectManager $om
     */
    public function __construct(ObjectManager $om)
    {
        $this->om = $om;
        $this->abilityRepo = $om->getRepository('HeVinciCompetencyBundle:Ability');
        $this->competencyRepo = $om->getRepository('HeVinciCompetencyBundle:Competency');
        $this->competencyAbilityRepo = $om->getRepository('HeVinciCompetencyBundle:CompetencyAbility');
        $this->abilityProgressRepo = $om->getRepository('HeVinciCompetencyBundle:Progress\AbilityProgress');
        $this->competencyProgressRepo = $om->getRepository('HeVinciCompetencyBundle:Progress\CompetencyProgress');
    }

    /**
     * Computes and logs the progression of a user.
     *
     * @param Evaluation $evaluation
     */
    public function handleEvaluation(Evaluation $evaluation)
    {
        $this->cachedProgresses = [];

        $activity = $evaluation->getActivityParameters()->getActivity();
        $abilities = $this->abilityRepo->findByActivity($activity);
        $user = $evaluation->getUser();

        foreach ($abilities as $ability) {
            $progress = $this->getAbilityProgress($ability, $user);

            if ($evaluation->isSuccessful() && !$progress->hasPassedActivity($activity)) {
                $progress->addPassedActivity($activity);

                if ($progress->getPassedActivityCount() >= $ability->getMinActivityCount()) {
                    $progress->setStatus(AbilityProgress::STATUS_ACQUIRED);
                } else {
                    $progress->setStatus(AbilityProgress::STATUS_PENDING);
                }

                $this->computeCompetencyProgress($ability, $user);
            }

            // failures must be tracked here (without progress re-computation)
        }

        $this->om->flush();
    }

    private function getAbilityProgress(Ability $ability, User $user)
    {
        $progress = $this->abilityProgressRepo->findOneBy([
            'ability' => $ability,
            'user' => $user
        ]);

        if (!$progress) {
            $progress = new AbilityProgress();
            $progress->setAbility($ability);
            $progress->setUser($user);
            $this->om->persist($progress);
        }

        return $progress;
    }

    private function computeCompetencyProgress(Ability $ability, User $user)
    {
        $competencyLinks = $this->competencyAbilityRepo->findBy(['ability' => $ability]);

        foreach ($competencyLinks as $link) {
            $competency = $link->getCompetency();
            $progress = $this->getCompetencyProgress($competency, $user);
            $progress->setLevel($link->getLevel());
            $progress->setPercentage(100);

            $relatedCompetencies = $this->competencyRepo->findForProgressComputing($competency);
            $this->computeParentCompetency($competency, $user, $relatedCompetencies);
        }
    }

    private function getCompetencyProgress(Competency $competency, User $user)
    {
        if (!isset($this->cachedProgresses[$competency->getId()])) {
            $progress = $this->competencyProgressRepo->findOneBy([
                'competency' => $competency,
                'user' => $user
            ]);

            if (!$progress) {
                $progress = new CompetencyProgress();
                $progress->setCompetency($competency);
                $progress->setUser($user);
                $this->om->persist($progress);
            } else {
                $this->om->persist($progress->makeLog());
            }

            $this->cachedProgresses[$competency->getId()] = $progress;
        }

        return $this->cachedProgresses[$competency->getId()];
    }

    private function computeParentCompetency(Competency $startNode, User $user, array $related)
    {
        if (!($parentNode = $this->getParentNode($startNode, $related))) {
            return;
        }

        $nodeProgress = $this->getCompetencyProgress($startNode, $user);
        $parentProgress = $this->getCompetencyProgress($parentNode, $user);
        $siblings = $this->getSiblingNodes($startNode, $parentNode, $related);

        if (0 === $siblingCount = count($siblings)) {
            $parentProgress->setPercentage($nodeProgress->getPercentage());
            $parentProgress->setLevel($nodeProgress->getLevel());
        } else {
            $percentageSum = $nodeProgress->getPercentage();
            $levelSum = $nodeProgress->getLevel()->getValue();
            $levelTerms = 1;

            foreach ($siblings as $sibling) {
                $siblingProgress = $this->getCompetencyProgress($sibling, $user);
                $percentageSum += $siblingProgress->getPercentage();
                $siblingLevel = $siblingProgress->getLevel();
                $levelSum += $siblingLevel ? $siblingLevel->getValue() : 0;
                $levelTerms += $siblingLevel ? 1 : 0;
            }

            $parentProgress->setPercentage((int) ($percentageSum / ($siblingCount + 1)));
            $parentProgress->setLevel($this->getLevel((int) ($levelSum / $levelTerms), $related));
        }

        $this->computeParentCompetency($parentNode, $user, $related);
    }

    private function getParentNode(Competency $startNode, array $related)
    {
        foreach ($related as $node) {
            if ($node->getLevel() === $startNode->getLevel() - 1
                && $node->getLeft() < $startNode->getLeft()
                && $node->getRight() > $startNode->getRight()) {
                return $node;
            }
        }

        return null;
    }

    private function getSiblingNodes(Competency $startNode, Competency $parent, array $related)
    {
        return array_filter($related, function ($node) use ($startNode, $parent) {
            return $node !== $startNode
                && $node->getLevel() === $startNode->getLevel()
                && $node->getLeft() > $parent->getLeft()
                && $node->getRight() < $parent->getRight();
        });
    }

    private function getLevel($value, array $related)
    {
        $root = null;

        foreach ($related as $competency) {
            if ($competency->getLevel() === 0) {
                $root = $competency;
                break;
            }
        }

        if (!$root) {
            throw new \Exception('Cannot find root competency in related nodes');
        }

        foreach ($root->getScale()->getLevels() as $level) {
            if ($level->getValue() === $value) {
                return $level;
            }
        }

        throw new \Exception("Cannot find level with value {$value}");
    }
}
