<?php
/**
 * Created by PhpStorm.
 * User: dhawal
 * Date: 4/29/16
 * Time: 11:02 PM
 */

namespace ClassCentral\SiteBundle\Services;


use ClassCentral\SiteBundle\Entity\Item;
use Guzzle\Http\Client;
use Symfony\Component\DependencyInjection\ContainerInterface;
use ClassCentral\SiteBundle\Entity\Course as CourseEntity;

class Course
{
    private $container;

    public static $UDEMY_API_URL = 'https://www.udemy.com/api-2.0';

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function uploadImageIfNecessary( $imageUrl, CourseEntity $course)
    {
        $kuber = $this->container->get('kuber');
        $uniqueKey = basename($imageUrl);
        if(strpos($uniqueKey,'?'))
        {
            $uniqueKey = reset(explode('?', $uniqueKey));
        }
        if( $kuber->hasFileChanged( Kuber::KUBER_ENTITY_COURSE,Kuber::KUBER_TYPE_COURSE_IMAGE, $course->getId(),$uniqueKey ) )
        {
            // Upload the file
            $filePath = '/tmp/course_'.$uniqueKey;
            file_put_contents($filePath,file_get_contents($imageUrl));
            $kuber->upload(
                $filePath,
                Kuber::KUBER_ENTITY_COURSE,
                Kuber::KUBER_TYPE_COURSE_IMAGE,
                $course->getId(),
                null,
                $uniqueKey
            );

        }
    }

    // Used for spotlight section
    public function getRandomPaidCourse()
    {
        $finder = $this->container->get('course_finder');
        $results = $finder->paidCourses();
        $courses = array();
        foreach($results['hits']['hits'] as $course)
        {
            $courses[] = $course['_source'];
        }

        $index = rand(0,count($courses)-1);

        return $courses[$index];
    }

    public function getRandomPaidCourseByProvider($providerName)
    {
        $finder = $this->container->get('course_finder');
        $results = $finder->byProvider('treehouse');

        $courses = array();
        foreach($results['hits']['hits'] as $course)
        {
            $courses[] = $course['_source'];
        }

        $index = rand(0,count($courses)-1);
        return $courses[$index];
    }

    public function getRandomPaidCourseExcludeByProvider($providerName)
    {
        $finder = $this->container->get('course_finder');
        $results = $finder->paidCourses();

        $courses = array();
        foreach($results['hits']['hits'] as $course)
        {
            if($course['_source']['provider']['name'] != $providerName)
            {
                $courses[] = $course['_source'];
            }
        }

        $index = rand(0,count($courses)-1);
        return $courses[$index];
    }

    public function getCourseImage(CourseEntity $course)
    {
        return $this->getCourseImageFromId($course->getId());
    }

    public function getCourseImageFromId($courseId)
    {
        $kuber = $this->container->get('kuber');
        $url = $kuber->getUrl( Kuber::KUBER_ENTITY_COURSE ,Kuber::KUBER_TYPE_COURSE_IMAGE, $courseId );
        return $url;
    }


    /**
     * Get additional info for the courses from json file
     */
    public function getCoursesAdditionalInfo()
    {
        $filePath = $this->container->get('kernel')->getRootDir(). '/../extras/add_course_info.json';
        $coursesJson = file_get_contents($filePath);
        if($coursesJson)
        {
            $courses = json_decode($coursesJson,true);
            return $courses;
        }

        return array();
    }

    /**
     * Gets additional information for a specific course
     * @param CourseEntity $course
     * @return array
     */
    public function getCourseAdditionalInfo(CourseEntity $course)
    {
        $coursesInfo = self::getCoursesAdditionalInfo();
        if(!empty($coursesInfo[$course->getId()]))
        {
            return $coursesInfo[$course->getId()];
        }

        return array();
    }

    /**
     * Gets the collection json
     * @param $slug
     */
    public function getCollection($slug)
    {
        $filePath = $this->container->get('kernel')->getRootDir(). '/../extras/collection.json';
        $coursesJson = file_get_contents($filePath);
        if($coursesJson)
        {
            $courses = json_decode($coursesJson,true);
            if(isset($courses[$slug]))
            {
                return $courses[$slug];
            }
        }

        return array();
    }

    public function getOldStackCourse($courseId)
    {
        $filePath = $this->container->get('kernel')->getRootDir(). '/../extras/coursera_old_stack.json';
        $coursesJson = file_get_contents($filePath);
        if($coursesJson)
        {
            $courses = json_decode($coursesJson,true);
            if(isset($courses[$courseId]))
            {
                return $courses[$courseId];
            }
        }
        return false;
    }

    /**
     * Get Udemy courses
     * $options contains fields mentioned here: https://www.udemy.com/developers/methods/get-courses-list/
     * @param $courses
     */
    public function getUdemyCourses($options = array())
    {
        $client = new Client();
        $clientId = $this->container->getParameter('udemy_client_id');
        $clientSecret = $this->container->getParameter('udemy_client_secret');
        $credentials = base64_encode("$clientId:$clientSecret");
        $query = http_build_query($options);
        $request =  $client->get(self::$UDEMY_API_URL . '/courses?'. $query, [
            'Authorization' => ['Basic '.$credentials]
        ]);

        $response = $request->send();

        if($response->getStatusCode() !== 200)
        {
            return array();
        }

        return json_decode($response->getBody(true),true);
    }

    /**
     * Given an array of institutions, it returns the courses taught by this institution.
     * @param array $institutions
     */
    public function getCourseIdsFromInstitutions($institutions = array())
    {
        $institutions = implode(',',$institutions);
        $conn = $this->container->get('doctrine')->getManager()->getConnection();
        $statement = $conn->prepare("SELECT course_id FROM courses_institutions WHERE institution_id in ($institutions);");
        $statement->execute();
        $results = $statement->fetchAll();
        $courseIds = array();
        foreach ($results as $result)
        {
            $courseIds[] = $result['course_id'];
        }

        return $courseIds;
    }

    public function getCoursesFromIds($courseIds)
    {
        $em = $this->container->get('doctrine')->getManager();
        $courses = [];
        foreach ($courseIds as $id)
        {
            $course = $em->getRepository('ClassCentralSiteBundle:Course')->find( $id);
            if($course)
            {
                $courses[] = $course;
            }
        }

        return $courses;
    }

    public function returnUniversitiesFromCourses($courses = [])
    {
        $universities = [];
        foreach ($courses as $course)
        {
            if($course->getInstitutions())
            {
                foreach ($course->getInstitutions() as $ins)
                {
                    if($ins->getIsUniversity())
                    {
                        $uniName = $ins->getName();
                        if(!isset($universities[$uniName]))
                        {
                            $universities[$uniName] = 0;

                        }
                        $universities[$uniName]++;
                    }
                }
            }
        }

        arsort($universities);

        return $universities;

    }

    public function sortByRatingAndFollows($courses = [])
    {
        uasort($courses,function($c1,$c2) {
            $rs = $this->container->get('review');
            $c1reviews = $rs->getReviews($c1->getId());
            $c1numRatings = $c1reviews['ratingCount'];

            if ($c1numRatings < 20 && $c1->isCourseNew()) {
                //$c1numRatings = 25;
            }

            $c2reviews = $rs->getReviews($c2->getId());
            $c2numRatings = $c2reviews['ratingCount'];
            if ($c2numRatings < 20 && $c2->isCourseNew()) {
                //$c2numRatings = 25;
            }

            if ($c1numRatings == $c2numRatings) {
                $follow = $this->container->get('follow');
                $c1Counts = 0;
                foreach ($c1->getInstitutions() as $ins) {
                    $c1Counts += $follow->getNumFollowers(Item::ITEM_TYPE_INSTITUTION, $ins->getId());
                }
                $c2Counts = 0;
                foreach ($c2->getInstitutions() as $ins) {
                    $c2Counts += $follow->getNumFollowers(Item::ITEM_TYPE_INSTITUTION, $ins->getId());
                }

                return $c1Counts < $c2Counts;
            } else {
                return $c1numRatings < $c2numRatings;
            }
        });

        return $courses;

    }

    public function categorizeCoursesBySubjects($courses = [])
    {
        $coursesByCategory = [];
        foreach ($courses as $course)
        {
            $category = $course->getStream();
            if($category->getParentStream())
            {
                $category = $category->getParentStream();
            }
            $category = $category->getName();
            if(!isset($coursesByCategory[$category]))
            {
                $coursesByCategory[$category] = [];
            }
            $coursesByCategory[$category][] = $course;
        }
        return $coursesByCategory;
    }

    public function getRandomNewCourseIds()
    {
        $cache = $this->container->get('cache');

        $courseIds = $cache->get('recently_added_courses', function (){
            $finder = $this->container->get('course_finder');
            $courses = $finder->byTime('recentlyAdded', [], [], -1);
            $courseIds = [];
            foreach ($courses['hits']['hits'] as $course)
            {
                $course = $course['_source'];
                $courseIds[] = $course['id'];
            }

            return $courseIds;
        });

        $randomCourseIds = [];
        for($i = 0 ; $i <= 5; $i++)
        {
            $randId = random_int(0,count($courseIds) - 1);
            $randomCourseIds[] = $courseIds[$randId];
        }

        return $randomCourseIds;
    }
}