<?php
/**
 * Created by PhpStorm.
 * User: dhawal
 * Date: 4/24/14
 * Time: 8:32 PM
 */

namespace ClassCentral\MOOCTrackerBundle\Job;


use ClassCentral\ElasticSearchBundle\Scheduler\SchedulerJobAbstract;
use ClassCentral\ElasticSearchBundle\Scheduler\SchedulerJobStatus;
use ClassCentral\SiteBundle\Entity\UserCourse;
use ClassCentral\SiteBundle\Utility\CourseUtility;
use InlineStyle\InlineStyle;

/**
 * Sends a reminder email for the course. Emails are sent on 2 occasion
 * - 2 weeks before the start date
 * - 1 day before
 * Class CourseStartReminderJob
 * @package ClassCentral\MOOCTrackerBundle\Job
 */
class CourseStartReminderJob extends SchedulerJobAbstract{

    const JOB_TYPE_2_WEEKS_BEFORE = 'email_reminder_course_start_2weeks';
    const JOB_TYPE_1_DAY_BEFORE   = 'email_reminder_course_start_1day';

    public function setUp()
    {
        // TODO: Implement setUp() method.
    }

    /**
     * Must return an object of type SchedulerJobStatus
     * @param $args
     * @return SchedulerJobStatus
     */
    public function perform($args)
    {
        $em = $this->getContainer()->get('doctrine')->getManager();
        $userId = $this->getJob()->getUserId();
        $user = $em->getRepository('ClassCentralSiteBundle:User')->findOneBy( array( 'id' => $userId) );
        $mailgun = $this->getContainer()->get('mailgun');

        if(!$user)
        {
            return SchedulerJobStatus::getStatusObject(
                SchedulerJobStatus::SCHEDULERJOB_STATUS_FAILED,
                "User with id $userId not found"
            );
        }

        // TODO: Implement this
       //var_dump( $args);
        $numCourses = 0;
        if(isset( $args[UserCourse::LIST_TYPE_INTERESTED]) )
        {
            $numCourses += count($args[UserCourse::LIST_TYPE_INTERESTED]);
        }
        if( isset($args[UserCourse::LIST_TYPE_ENROLLED]) )
        {
            $numCourses += count( $args[UserCourse::LIST_TYPE_ENROLLED] );
        }


        if( $numCourses == 1)
        {
            $courseId = null;

            $isInterested = empty( $args[UserCourse::LIST_TYPE_INTERESTED] ) ? false : true;
            if( $isInterested )
            {
                $courseId = array_pop( $args[UserCourse::LIST_TYPE_INTERESTED]) ;
            }
            else
            {
                $courseId = array_pop( $args[UserCourse::LIST_TYPE_ENROLLED] );
            }
            $course = $em->getRepository('ClassCentralSiteBundle:Course')->find( $courseId );
            $html = $this->getCourseView( $course, $isInterested, $user, $this->getJob()->getJobType() );

            $subject = "Reminder : ";
            $subject .= $course->getName() ;
            $subject .= ( $this->getJob()->getJobType() == self::JOB_TYPE_1_DAY_BEFORE )  ?
                " starts tomorrow" : " starts soon";

            $response = $mailgun->sendMessage( array(
                'from' => '"MOOC Tracker" <no-reply@class-central.com>',
                //'to' => $user->getEmail(),
                //'to' => 'dhawalhshah@gmail.com',
                'to' => 'dhawal@class-central.com',
                'subject' => $subject,
                'html' => $html
            ));

            if( !($response && $response->http_response_code == 200))
            {
                // Failed
                return SchedulerJobStatus::getStatusObject(
                    SchedulerJobStatus::SCHEDULERJOB_STATUS_FAILED,
                    ($response && $response->http_response_body)  ?
                        $response->http_response_body->message : "Mailgun error"
                );
            }

        }
        else
        {
            // Multiple courses


        }


        /*
        Different scenarios
        1. 1 interested course
        2. 1 enrolled course
        3. Multiple interested course
        4. Multiple enrolled course
        5. Mix of interested and enrolled
        6. Start today or Starting Soon
        */

        return SchedulerJobStatus::getStatusObject(SchedulerJobStatus::SCHEDULERJOB_STATUS_SUCCESS, "Email sent");

    }

    private function getCourseView($course, $isInterested, $user, $jobType)
    {

        $em = $this->getContainer()->get('doctrine')->getManager();
        $courseDetails = $em->getRepository('ClassCentralSiteBundle:Course')->getCourseArray( $course );

        $templating = $this->getContainer()->get('templating');
        return $templating->renderResponse('ClassCentralMOOCTrackerBundle:Reminder:single.course.inlined.html', array(
            'course' => $courseDetails,
            'baseUrl' => $this->getContainer()->getParameter('baseurl'),
            'interested' => $isInterested,
            'user' => $user,
            'jobType' => $jobType
        ))->getContent();;

    }

    public function tearDown()
    {
        // TODO: Implement tearDown() method.
    }
}