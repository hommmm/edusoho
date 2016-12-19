<?php
namespace Topxia\Service\Sms\Event;

use Codeages\Biz\Framework\Event\Event;
use Topxia\Common\StringToolkit;
use Topxia\Service\Common\ServiceEvent;
use Topxia\Service\Common\ServiceKernel;
use Topxia\Service\CloudPlatform\CloudAPIFactory;
use Topxia\Service\Sms\SmsProcessor\SmsProcessorFactory;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class SmsEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents()
    {
        return array(
            'testpaper.reviewed'         => 'onTestpaperReviewed',
            'order.pay.success'          => 'onOrderPaySuccess',
            'course.lesson.publish'      => 'onCourseLessonPublish',
            'course.lesson.update'       => 'onCourseLessonUpdate',
            'course.lesson.delete'       => 'onCourseLessonDelete',
            'course.lesson.unpublish'    => 'onCourseLessonUnpublish',
            'open.course.lesson.publish' => 'onLiveOpenCourseLessonCreate',
            'open.course.lesson.update'  => 'onLiveOpenCourseLessonUpdate'
        );
    }

    public function onTestpaperReviewed(Event $event)
    {
        $parameters = array();
        $smsType    = 'sms_testpaper_check';

        if ($this->getSmsService()->isOpen($smsType)) {
            $testpaper       = $event->getSubject();
            $testpaperResult = $event->getArgument('testpaperResult');

            $target = explode('-', $testpaper['target']);

            if ($target[0] == 'course') {
                $courseId                     = $target[1];
                $course                       = $this->getCourseService()->getCourse($courseId);
                $testpaperResult['paperName'] = StringToolkit::cutter($testpaperResult['paperName'], 20, 15, 4);
                $course['title']              = StringToolkit::cutter($course['title'], 20, 15, 4);
                $parameters['lesson_title']   = '《'.$testpaperResult['paperName'].'》'.$this->getKernel()->trans('试卷');
                $parameters['course_title']   = '《'.$course['title'].'》';
                $description                  = $parameters['course_title'].' '.$parameters['lesson_title'].$this->getKernel()->trans('试卷批阅提醒');
                $userId                       = $testpaperResult['userId'];
                $this->getSmsService()->smsSend($smsType, array($userId), $description, $parameters);
            }
        }
    }

    public function onOrderPaySuccess(Event $event)
    {
        $order      = $event->getSubject();
        $targetType = $event->getArgument('targetType');
        $smsType    = 'sms_'.$targetType.'_buy_notify';

        if ($this->getSmsService()->isOpen($smsType)) {
            $userId                    = $order['userId'];
            $parameters                = array();
            $parameters['order_title'] = $order['title'];
            $parameters['order_title'] = StringToolkit::cutter($parameters['order_title'], 20, 15, 4);

            if ($targetType == 'coin') {
                $parameters['totalPrice'] = $order['amount'].$this->getKernel()->trans('元');
            } else {
                $parameters['totalPrice'] = $order['totalPrice'].$this->getKernel()->trans('元');
            }

            $description = $parameters['order_title'].$this->getKernel()->trans('成功回执');

            $this->getSmsService()->smsSend($smsType, array($userId), $description, $parameters);
        }
    }

    public function onCourseLessonPublish(Event $event)
    {
        $lesson = $event->getSubject();

        if ($lesson['type'] == 'live') {
            $this->createJob($lesson, 'lesson');
            $smsType = 'sms_live_lesson_publish';
        } else {
            $smsType = 'sms_normal_lesson_publish';
        }

        if ($this->getSmsService()->isOpen($smsType)) {
            $processor    = SmsProcessorFactory::create('lesson');
            $return       = $processor->getUrls($lesson['id'], $smsType);
            $callbackUrls = $return['urls'];
            $count        = ceil($return['count'] / 1000);
            try {
                $api    = CloudAPIFactory::create('root');
                $result = $api->post("/sms/sendBatch", array('total' => $count, 'callbackUrls' => $callbackUrls));
            } catch (\RuntimeException $e) {
                $description = $parameters['order_title'].$this->getKernel()->trans('成功回执');
                throw new \RuntimeException($this->getKernel()->trans('发送失败！'));
            }
        }
    }

    public function onCourseLessonUnpublish(Event $event)
    {
        $lesson = $event->getSubject();
        $jobs   = $this->getCrontabService()->findJobByTargetTypeAndTargetId('lesson', $lesson['id']);

        if ($jobs) {
            $this->deleteJob($jobs);
        }
    }

    public function onCourseLessonUpdate(Event $event)
    {
        $context  = $event->getSubject();
        $argument = $context['argument'];
        $lesson   = $context['lesson'];

        if ($lesson['type'] == 'live' && isset($argument['startTime']) && $argument['startTime'] != $lesson['fields']['startTime'] && ($this->getSmsService()->isOpen('sms_live_play_one_day') || $this->getSmsService()->isOpen('sms_live_play_one_hour'))) {
            $jobs = $this->getCrontabService()->findJobByTargetTypeAndTargetId('lesson', $lesson['id']);

            if ($jobs) {
                $this->deleteJob($jobs);
            }

            if ($lesson['status'] == 'published') {
                $this->createJob($lesson, 'lesson');
            }
        }
    }

    public function onCourseLessonDelete(Event $event)
    {
        $context = $event->getSubject();
        $lesson  = $context['lesson'];
        $jobs    = $this->getCrontabService()->findJobByTargetTypeAndTargetId('lesson', $lesson['id']);

        if ($jobs) {
            $this->deleteJob($jobs);
        }
    }

    public function onLiveOpenCourseLessonCreate(Event $event)
    {
        $lesson = $event->getSubject();

        if ($lesson['type'] == 'liveOpen' && isset($lesson['startTime'])
            && ($this->getSmsService()->isOpen('sms_live_play_one_day') || $this->getSmsService()->isOpen('sms_live_play_one_hour'))
        ) {
            $this->createJob($lesson, 'liveOpenLesson');
        }
    }

    public function onLiveOpenCourseLessonUpdate(Event $event)
    {
        $context = $event->getSubject();
        $lesson  = $context['lesson'];

        if ($lesson['type'] == 'liveOpen' && isset($lesson['startTime'])
            && $lesson['startTime'] != $lesson['fields']['startTime']
            && ($this->getSmsService()->isOpen('sms_live_play_one_day') || $this->getSmsService()->isOpen('sms_live_play_one_hour'))
        ) {
            $jobs = $this->getCrontabService()->findJobByTargetTypeAndTargetId('liveOpenLesson', $lesson['id']);

            if ($jobs) {
                $this->deleteJob($jobs);
            }

            if ($lesson['status'] == 'published') {
                $this->createJob($lesson, 'liveOpenLesson');
            }
        }
    }

    protected function createJob($lesson, $targetType)
    {
        $daySmsType  = 'sms_live_play_one_day';
        $hourSmsType = 'sms_live_play_one_hour';
        $dayIsOpen   = $this->getSmsService()->isOpen($daySmsType);
        $hourIsOpen  = $this->getSmsService()->isOpen($hourSmsType);

        if ($dayIsOpen && $lesson['startTime'] >= (time() + 24 * 60 * 60)) {
            $startJob = array(
                'name'            => "SmsSendOneDayJob",
                'cycle'           => 'once',
                'nextExcutedTime' => $lesson['startTime'] - 24 * 60 * 60,
                'jobClass'        => substr(__NAMESPACE__, 0, -5).'Job\\SmsSendOneDayJob',
                'targetType'      => $targetType,
                'targetId'        => $lesson['id']
            );
            $startJob = $this->getCrontabService()->createJob($startJob);
        }

        if ($hourIsOpen && $lesson['startTime'] >= (time() + 60 * 60)) {
            $startJob = array(
                'name'            => "SmsSendOneHourJob",
                'cycle'           => 'once',
                'nextExcutedTime' => $lesson['startTime'] - 60 * 60,
                'jobClass'        => substr(__NAMESPACE__, 0, -5).'Job\\SmsSendOneHourJob',
                'targetType'      => $targetType,
                'targetId'        => $lesson['id']
            );
            $startJob = $this->getCrontabService()->createJob($startJob);
        }
    }

    protected function deleteJob($jobs)
    {
        foreach ($jobs as $key => $job) {
            if ($job['name'] == 'SmsSendOneDayJob' || $job['name'] == 'SmsSendOneHourJob') {
                $this->getCrontabService()->deleteJob($job['id']);
            }
        }
    }

    protected function getUserService()
    {
        return ServiceKernel::instance()->getBiz()->service('User:UserService');
    }

    protected function getTestpaperService()
    {
        return ServiceKernel::instance()->createService('Testpaper.TestpaperService');
    }

    protected function getSmsService()
    {
        return ServiceKernel::instance()->createService('Sms.SmsService');
    }

    protected function getCourseService()
    {
        return ServiceKernel::instance()->createService('Course.CourseService');
    }

    protected function getCrontabService()
    {
        return ServiceKernel::instance()->createService('Crontab.CrontabService');
    }

    protected function getKernel()
    {
        return ServiceKernel::instance();
    }
}
