<?php

namespace AppBundle\Controller\My;

use AppBundle\Common\ArrayToolkit;
use AppBundle\Common\Paginator;
use AppBundle\Controller\BaseController;
use Biz\ItemBankExercise\ItemBankExerciseException;
use Biz\ItemBankExercise\Service\ExerciseMemberService;
use Biz\ItemBankExercise\Service\ExerciseModuleService;
use Biz\ItemBankExercise\Service\ExerciseService;
use Biz\QuestionBank\Service\QuestionBankService;
use Symfony\Component\HttpFoundation\Request;

class ItemBankExerciseController extends BaseController
{
    public function teachingAction(Request $request, $filter)
    {
        $user = $this->getCurrentUser();

        if (!$user->isTeacher()) {
            return $this->createMessageResponse('error', 'my.teaching.view.forbidden');
        }

        $conditions = [
            'teacherIds' => $user['id'],
        ];

        $paginator = new Paginator(
            $request,
            $this->getItemBankExerciseService()->count($conditions),
            10
        );

        $itemBankExercises = $this->getItemBankExerciseService()->search(
            $conditions,
            ['createdTime' => 'DESC'],
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $questionBanks = ArrayToolkit::column($itemBankExercises, 'questionBankId');
        $questionBanks = $this->getQuestionBankService()->findQuestionBanksByIds($questionBanks);
        $questionBanks = ArrayToolkit::index($questionBanks, 'id');
        foreach ($itemBankExercises as &$itemBankExercise) {
            $itemBankExercise['assessmentNum'] = $questionBanks[$itemBankExercise['questionBankId']]['itemBank']['assessment_num'];
            $itemBankExercise['itemNum'] = $questionBanks[$itemBankExercise['questionBankId']]['itemBank']['item_num'];
        }

        return $this->render('my/teaching/item-bank-exercise.html.twig', [
            'itemBankExercises' => $itemBankExercises,
            'paginator' => $paginator,
            'filter' => $filter,
        ]);
    }

    public function showAction(Request $request, $id, $tab = 'reviews')
    {
        $user = $this->getCurrentUser();
        $exercise = $this->getItemBankExerciseService()->get($id);
        if (empty($exercise)) {
            $this->createNewException(ItemBankExerciseException::NOTFOUND_EXERCISE());
        }

        $member = $user['id'] ? $this->getExerciseMemberService()->getExerciseMember($exercise['id'], $user['id']) : null;

        if (empty($member) || ('date' == $exercise['expiryMode'] && $exercise['expiryStartDate'] >= time())) {
            return $this->redirectToRoute('course_show', ['id' => $id]);
        }

        return $this->render(
            'item-bank-exercise/exercise-show.html.twig',
            [
                'tab' => $tab,
                'tabs' => $this->getExerciseModuleService()->findByExerciseId($id),
                'member' => $member,
                'isExerciseTeacher' => 'teacher' == $member['role'],
                'exercise' => $exercise,
            ]
        );
    }

    public function headerAction(Request $request, $exercise)
    {
        $user = $this->getCurrentUser();

        $member = $user->isLogin() ? $this->getExerciseMemberService()->getExerciseMember($exercise['id'], $user['id']) : [];

        return $this->render(
            'item-bank-exercise/header/header-for-member.html.twig',
            [
                'member' => $member,
                'exercise' => $exercise,
            ]
        );
    }

    /**
     * @return ExerciseMemberService
     */
    protected function getExerciseMemberService()
    {
        return $this->createService('ItemBankExercise:ExerciseMemberService');
    }

    /**
     * @return ExerciseService
     */
    protected function getItemBankExerciseService()
    {
        return $this->createService('ItemBankExercise:ExerciseService');
    }

    /**
     * @return QuestionBankService
     */
    protected function getQuestionBankService()
    {
        return $this->createService('QuestionBank:QuestionBankService');
    }

    /**
     * @return ExerciseModuleService
     */
    protected function getExerciseModuleService()
    {
        return $this->createService('ItemBankExercise:ExerciseModuleService');
    }
}
