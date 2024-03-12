<?php

namespace DTApi\Repository;

use App\Enums\FieldEnum;
use App\Enums\JobCertifiedEnum;
use App\Enums\TranslatorTypeEnum;
use App\Enums\TranslatorLevelEnum;
use App\Enums\MessageTemplateEnum;
use DTApi\Events\SessionEnded;
use DTApi\Helpers\SendSMSHelper;
use Event;
use Carbon\Carbon;
use Monolog\Logger;
use DTApi\Models\Job;
use DTApi\Models\User;
use DTApi\Models\Language;
use DTApi\Models\UserMeta;
use DTApi\Helpers\TeHelper;
use Illuminate\Http\Request;
use DTApi\Models\Translator;
use DTApi\Mailers\AppMailer;
use DTApi\Models\UserLanguages;
use DTApi\Events\JobWasCreated;
use DTApi\Events\JobWasCanceled;
use DTApi\Models\UsersBlacklist;
use DTApi\Helpers\DateTimeHelper;
use DTApi\Mailers\MailerInterface;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\FirePHPHandler;
use Illuminate\Support\Facades\Auth;

/**
 * Class BookingRepository
 * @package DTApi\Repository
 */
class BookingRepository extends BaseRepository
{
    protected $model;
    protected $mailer;
    protected $logger;

    /**
     * BookingRepository Constructor
     * @param Job $model 
     * @param MailerInterface $mailer 
     */
    function __construct(Job $model, MailerInterface $mailer)
    {
        parent::__construct($model);
        $this->mailer = $mailer;
        $this->logger = new Logger('admin_logger');

        $this->logger->pushHandler(new StreamHandler(storage_path('logs/admin/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());
    }

    /**
     * Get Users Jobs 
     * @param int $user_id
     * @return array
     */
    public function getUsersJobs($user_id)
    {
        $cuser = User::find($user_id);
        $usertype = '';
        $emergency_jobs = array();
        $normal_jobs = array();

        if ($cuser && $cuser->is('customer')) {
            $jobs = $cuser->jobs()
                ->with(['user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback'])
                ->whereIn('status', ['pending', 'assigned', 'started'])
                ->orderBy('due', 'asc')
                ->get();

            $usertype = 'customer';
        } elseif ($cuser && $cuser->is('translator')) {
            $jobs = Job::getTranslatorJobs($cuser->id, 'new');
            $jobs = $jobs->pluck('jobs')->all();
            $usertype = 'translator';
        }

        if ($jobs) {
            foreach ($jobs as $jobitem) {
                if ($jobitem->immediate == 'yes') {
                    $emergency_jobs[] = $jobitem;
                } else {
                    $normal_jobs[] = $jobitem;
                }
            }

            $normal_jobs = collect($jobs)->filter(fn ($jobItem) => $jobItem->immediate != 'yes');
            $normal_jobs = $normal_jobs->map(function ($jobItem) use ($user_id) { 
                $jobItem->usercheck = Job::checkParticularJob($user_id, $jobItem);
                return $jobItem;
            })
            ->sortBy('due')
            ->all();
            
            $emergency_jobs = collect($jobs)->filter(fn ($jobItem) => $jobItem->immediate == 'yes')->toArray();
        }

        return [
            'emergencyJobs' => $emergency_jobs,
            'normalJobs' => $normal_jobs,
            'cuser' => $cuser,
            'usertype' => $usertype
        ];
    }

    /**
     * Get Users Jobs History
     * @param int $user_id
     * @param Request $request
     * @return array
     */
    public function getUsersJobsHistory($user_id, Request $request)
    {
        $page = $request->get('page');
        $pagenum = (isset($page)) ? $page : "1";
        $numpages = 0;
        
        $cuser = User::find($user_id);
        $usertype = '';
        
        $emergency_jobs = array();
        $normal_jobs = array();

        if ($cuser && $cuser->is('customer')) {
            $pagenum = 0;
            $usertype = 'customer';

            $jobs = $cuser->jobs()
                ->with('user.userMeta', 'user.average', 'translatorJobRel.user.average', 'language', 'feedback', 'distance')
                ->whereIn('status', ['completed', 'withdrawbefore24', 'withdrawafter24', 'timedout'])
                ->orderBy('due', 'desc')
                ->paginate(15);

        } elseif ($cuser && $cuser->is('translator')) {
            $usertype = 'translator';

            $jobs_ids = Job::getTranslatorJobsHistoric($cuser->id, 'historic', $pagenum);
            $totaljobs = $jobs_ids->total();
            $numpages = ceil($totaljobs / 15);

            $jobs = $jobs_ids;
            $normal_jobs = $jobs_ids;
        }

        return [
            'emergencyJobs' => $emergency_jobs, 
            'normalJobs' => $normal_jobs, 
            'jobs' => $jobs, 
            'cuser' => $cuser, 
            'usertype' => $usertype, 
            'numpages' => $numpages, 
            'pagenum' => $pagenum
        ];
    }

    /**
     * Store Record
     * @param User $user
     * @param array $data
     * @return array
     */
    public function store($user, $data)
    {
        if ($user->user_type != env('CUSTOMER_ROLE_ID')) {
            $response['status'] = 'fail';
            $response['message'] = "Translator can not create booking";

            return $response;
        }

        $immediatetime = 5;
        $consumer_type = $user->userMeta->consumer_type;
        $cuser = $user;

        if (!isset($data['from_language_id'])) {
            return FieldEnum::FROM_LANGUAGE_ID->response();
        }
        
        if ($data['immediate'] == 'no') {
            if (isset($data['due_date']) && $data['due_date'] == '') {
                return FieldEnum::DUE_DATE->response();
            }
            if (isset($data['due_time']) && $data['due_time'] == '') {
                return FieldEnum::DUE_TIME->response();
            }
            if (!isset($data['customer_phone_type']) && !isset($data['customer_physical_type'])) {
                return FieldEnum::NO_PHONE_NO_PHYSICAL->response();
            }
            if (isset($data['duration']) && $data['duration'] == '') {
                return FieldEnum::DURATION->response();
            }
        }
        
        if (isset($data['duration']) && $data['duration'] == '') {
            return FieldEnum::DURATION->response();
        }
        
        $data['customer_phone_type'] = isset($data['customer_phone_type']) ? 'yes' : 'no';
        $data['customer_physical_type'] = isset($data['customer_physical_type']) ? 'yes' : 'no';
        $response['customer_physical_type'] = isset($response['customer_physical_type']) ? 'yes' : 'no';
        
        $due = ($data['immediate'] == 'yes') ? $immediatetime : $data['due_date'] . " " . $data['due_time'];
        $due_carbon = ($data['immediate'] == 'yes') ? Carbon::now()->addMinute($immediatetime) : Carbon::createFromFormat('m/d/Y H:i', $due);
        $data['due'] = ($data['immediate'] == 'yes') ? 'immediate' : 'regular';
        $data['customer_phone_type'] = ($data['immediate'] == 'yes') ? 'yes' : $data['customer_phone_type'];
        $response['type'] = ($data['immediate'] == 'yes') ? 'immediate' : 'regular';

        if ($due_carbon->isPast() && $data['immediate'] == 'no') {
            $response['status'] = 'fail';
            $response['message'] = "Can't create booking in past";
            return $response;
        }

        $data['gender'] = (in_array('male', $data['job_for'])) ? 'male' : null;
        $data['gender'] = (in_array('femail', $data['job_for'])) ? 'female' : $data['gender'];

        $data['certified'] = (in_array('normal', $data['job_for'])) ? 'normal' : null;
        $data['certified'] = (in_array('certified', $data['job_for'])) ? 'yes' : $data['certified'];
        $data['certified'] = (in_array('certified_in_law', $data['job_for'])) ? 'law' : $data['certified'];
        $data['certified'] = (in_array('certified_in_helth', $data['job_for'])) ? 'health' : $data['certified'];

        if (in_array('normal', $data['job_for'])) {
            $data['certified'] = in_array('certified', $data['job_for']) ? 'both' : $data['certified'];
            $data['certified'] = in_array('certified_in_law', $data['job_for']) ? 'n_law' : $data['certified'];
            $data['certified'] = in_array('certified_in_helth', $data['job_for']) ? 'n_health' : $data['certified'];
        }

        $data['job_type'] = ($consumer_type == 'rwsconsumer') ? 'rws' : null;
        $data['job_type'] = ($consumer_type == 'ngo') ? 'unpaid' : $data['job_type'];
        $data['job_type'] = ($consumer_type == 'paid') ? 'paid' : $data['job_type'];
        
        $data['b_created_at'] = date('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($due, $data['b_created_at']);
        $data['by_admin'] = $data['by_admin'] ?? 'no';

        $job = $cuser->jobs()->create($data);

        $response['status'] = 'success';
        $response['id'] = $job->id;

        $data['job_for'] = array();

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        $data['customer_town'] = $cuser->userMeta->city;
        $data['customer_type'] = $cuser->userMeta->customer_type;

        return $response;
    }

    /**
     * Store Job Email
     * @param array $data
     * @return array
     */
    public function storeJobEmail($data)
    {
        $user_type = $data['user_type'];

        $job = Job::findOrFail(@$data['user_email_job_id']);
        $job->user_email = @$data['user_email'];
        $job->reference = $data['reference'] ?? '';

        $user = $job->user()->first();

        if (isset($data['address'])) {
            $job->address = $data['address'] ?: $user->userMeta->address;
            $job->instructions = $data['instructions'] ?: $user->userMeta->instructions;
            $job->town = $data['town'] ?: $user->userMeta->city;
        }

        $job->save();
        
        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = (!empty($job->user_email)) ? $user->name : $user->name;
        
        $subject = 'Vi har mottagit er tolkbokning. Bokningsnr: #' . $job->id;

        $send_data['user'] = $user;
        $send_data['job'] = $job;
        // Send to Queue if queue-able
        $this->mailer->send($email, $name, $subject, 'emails.job-created', $send_data);

        $response['type'] = $user_type;
        $response['job'] = $job;
        $response['status'] = 'success';
        
        $data = $this->jobToData($job);
        Event::fire(new JobWasCreated($job, $data, '*'));

        return $response;
    }

    /**
     * Save Job's information to data for sending Push
     * @param Job $job
     * @return array
     */
    public function jobToData($job)
    {
        $data = $job->only([
            'job_id',
            'from_language_id',
            'immediate',
            'duration',
            'status',
            'gender',
            'certified',
            'due',
            'job_type',
            'customer_phone_type',
            'customer_physical_type',
        ]);
        
        $data['customer_town'] = $job->town;
        $data['customer_type'] = $job->user->userMeta->customer_type;

        $due_date = explode(" ", $job->due);
        $data['due_date'] = $due_date[0];
        $data['due_time'] = $due_date[1];

        $data['job_for'] = array();

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }

        if ($job->certified != null) {
            $job_certified_enum = JobCertifiedEnum::tryFrom($job->certified);
            $data['job_for'] = array_merge($data['job_for'], $job_certified_enum->label());
        }

        return $data;
    }

    /**
     * Job End
     * @param array $post_data
     * @return void
     */
    public function jobEnd($post_data = array())
    {
        $completed_date = Carbon::now()->format('Y-m-d H:i:s');

        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);
        
        $duedate = $job_detail->due;
        $start = Carbon::parse($duedate);
        $end = Carbon::parse($completed_date);
        $diff = $end->diff($start);
        $interval = $diff->format('%h:%i:%s');

        $job = $job_detail;
        $job->end_at = Carbon::now()->format('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = "Information om avslutad tolkning för bokningsnummer # {$job->id}";
        $session_time = $diff->format('%h tim :%i min');

        $data['user'] = $user;
        $data['job'] = $job;
        $data['session_time'] = $session_time;
        $data['for_text'] = 'faktura';

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel->whereNull('completed_at')->whereNull('cancel_at')->first();

        $user_id = ($post_data['userid'] == $job->user_id) ? $tr->user_id : $job->user_id;
        Event::fire(new SessionEnded($job, $user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = "Information om avslutad tolkning för bokningsnummer # {$job->id}";

        $data['user'] = $user;
        $data['job'] = $job;
        $data['session_time'] = $session_time;
        $data['for_text'] = 'lön';

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completed_date;
        $tr->completed_by = $post_data['userid'];

        $tr->save();
    }

    /**
     * Function to get all Potential jobs of user with his ID
     * @param int $user_id
     * @return array
     */
    public function getPotentialJobIdsWithUserId($user_id)
    {
        $user_meta = UserMeta::where('user_id', $user_id)->first();
        
        $translator_type = $user_meta->translator_type;
        
        $job_type = 'unpaid';
        $translator_type_enum = TranslatorTypeEnum::tryFrom($translator_type);
        $job_type = $translator_type_enum->type();

        $languages = UserLanguages::where('user_id', $user_id)->get();
        $userlanguage = $languages->pluck('lang_id')->all();

        $gender = $user_meta->gender;
        $translator_level = $user_meta->translator_level;

        $job_ids = Job::getJobs($user_id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $v) { // checking translator town
            $job = Job::find($v->id); // Not sure what Job::getJobs returns.... ?
            $jobuserid = $job->user_id;
            $checktown = Job::checkTowns($jobuserid, $user_id);

            if (in_array($job->customer_phone_type, ['no', '']) && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }
        
        $jobs = TeHelper::convertJobIdsInObjs($job_ids);

        return $jobs;
    }

    /**
     * Send notification translator
     * @param Job $job
     * @param array $data
     * @param int $exclude_user_id
     * @return void
     */
    public function sendNotificationTranslator($job, $data = [], $exclude_user_id)
    {
        $users = User::all();
        $translator_array = array(); // suitable translators (no need to delay push)
        $delpay_translator_array = array(); // suitable translators (need to delay push)

        foreach ($users as $one_user) {
            // We can implement here a method like $one_user->isNotDisabledtranslator() 
            if ($one_user->user_type == '2' && $one_user->status == '1' && $one_user->id != $exclude_user_id) { // user is translator and he is not disabled
                if (!$this->isNeedToSendPush($one_user->id)) {
                    continue;
                }

                $not_get_emergency = TeHelper::getUsermeta($one_user->id, 'not_get_emergency');

                if ($data['immediate'] == 'yes' && $not_get_emergency == 'yes') {
                    continue;
                }

                $jobs = $this->getPotentialJobIdsWithUserId($one_user->id); // get all potential jobs of this user

                foreach ($jobs as $oneJob) {
                    if ($job->id == $oneJob->id) { // one potential job is the same with current job
                        $user_id = $one_user->id;
                        $job_for_translator = Job::assignedToPaticularTranslator($user_id, $oneJob->id);

                        if ($job_for_translator == 'SpecificJob') {
                            $job_checker = Job::checkParticularJob($user_id, $oneJob);

                            if (($job_checker != 'userCanNotAcceptJob')) {
                                if ($this->isNeedToDelayPush($one_user->id)) {
                                    $delpay_translator_array[] = $one_user;
                                } else {
                                    $translator_array[] = $one_user;
                                }
                            }
                        }
                    }
                }
            }
        }

        $data['language'] = TeHelper::fetchLanguageFromJobId($data['from_language_id']);
        $data['notification_type'] = 'suitable_job';
        $msg_contents = '';
        
        $msg_contents = ($data['immediate'] == 'no') 
            ? 'Ny bokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min ' . $data['due'] 
            : 'Ny akutbokning för ' . $data['language'] . 'tolk ' . $data['duration'] . 'min';
        
        $msg_text['en'] = $msg_contents;

        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job->id, [$translator_array, $delpay_translator_array, $msg_text, $data]);

        $this->sendPushNotificationToSpecificUsers($translator_array, $job->id, $data, $msg_text, false);       // send new booking push to suitable translators(not delay)
        $this->sendPushNotificationToSpecificUsers($delpay_translator_array, $job->id, $data, $msg_text, true); // send new booking push to suitable translators(need to delay)
    }

    /**
     * Sends SMS to translators and retuns count of translators
     * @param Job $job
     * @return int
     */
    public function sendSMSNotificationToTranslator($job)
    {
        $translators = $this->getPotentialTranslators($job);
        $job_poster_meta = UserMeta::where('user_id', $job->user_id)->first();

        // prepare message templates
        $date = Carbon::parse($job->due)->format('d.m.Y');
        $time = Carbon::parse($job->due)->format('H:i');
        $duration = $this->convertToHoursMins($job->duration);
        
        $job_id = $job->id;
        $city = $job->city ? $job->city : $job_poster_meta->city;

        $phone_job_message_template = trans('sms.phone_job', [
            'date' => $date, 
            'time' => $time, 
            'duration' => $duration, 
            'jobId' => $job_id
        ]);

        $physical_job_message_template = trans('sms.physical_job', [
            'date' => $date, 
            'time' => $time, 
            'town' => $city, 
            'duration' => $duration, 
            'jobId' => $job_id
        ]);

        $template = MessageTemplateEnum::deternmine($job->customer_physical_type, $job->customer_phone_type);
        $message = $template->getMessageTemplate($physical_job_message_template, $phone_job_message_template);

        // send message to translator via sms handler
        foreach ($translators as $translator) {
            $status = SendSMSHelper::send(env('SMS_NUMBER'), $translator->mobile, $message);
            $this->logger->addInfo('Send SMS to ' . $translator->email . ' (' . $translator->mobile . '), status: ' . print_r($status, true));
        }

        return count($translators);
    }

    /**
     * Function to delay the push
     * @param int $user_id
     * @return bool
     */
    public function isNeedToDelayPush($user_id)
    {
        if (!DateTimeHelper::isNightTime()) { 
            return false;
        }

        $not_get_nighttime = TeHelper::getUsermeta($user_id, 'not_get_nighttime');
        
        if ($not_get_nighttime == 'yes') {
            return true;
        }

        return false;
    }

    /**
     * Function to check if need to send the push
     * @param int $user_id
     * @return bool
     */
    public function isNeedToSendPush($user_id)
    {
        $not_get_notification = TeHelper::getUsermeta($user_id, 'not_get_notification');

        if ($not_get_notification == 'yes') {
            return false;
        }
        
        return true;
    }

    /**
     * Function to send Onesignal Push Notifications with User-Tags
     * @param $users
     * @param $job_id
     * @param $data
     * @param $msg_text
     * @param $is_need_delay
     * @return void
     */
    public function sendPushNotificationToSpecificUsers($users, $job_id, $data, $msg_text, $is_need_delay)
    {
        $logger = new Logger('push_logger');

        $logger->pushHandler(new StreamHandler(storage_path('logs/push/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $logger->pushHandler(new FirePHPHandler());
        $logger->addInfo('Push send for job ' . $job_id, [$users, $data, $msg_text, $is_need_delay]);

        $one_signal_app_id = (env('APP_ENV') == 'prod') 
            ? config('app.prodOnesignalAppID') 
            : config('app.devOnesignalAppID');

        $one_signal_rest_auth_key = (env('APP_ENV') == 'prod') 
            ? sprintf("Authorization: Basic %s", config('app.prodOnesignalApiKey')) 
            : sprintf("Authorization: Basic %s", config('app.devOnesignalApiKey'));
        
        $user_tags = $this->getUserTagsStringFromArray($users);

        $data['job_id'] = $job_id;
        $ios_sound = 'default';
        $android_sound = 'default';

        if ($data['notification_type'] == 'suitable_job') {
            $android_sound = ($data['immediate'] == 'no') ? 'normal_booking' : 'emergency_booking';
            $ios_sound = ($data['immediate'] == 'no') ? 'normal_booking.mp3' : 'emergency_booking.mp3';
        }
        
        $fields['app_id'] = $one_signal_app_id;
        $fields['tags'] = json_decode($user_tags);
        $fields['data'] = $data;
        $fields['title'] = ['en' => 'DigitalTolk'];
        $fields['contents'] = $msg_text;
        $fields['ios_badgeType'] = 'Increase';
        $fields['ios_badgeCount'] = 1;
        $fields['android_sound'] = $android_sound;
        $fields['ios_sound'] = $ios_sound;

        if ($is_need_delay) {
            $next_business_time = DateTimeHelper::getNextBusinessTimeString();
            $fields['send_after'] = $next_business_time;
        }

        $fields = json_encode($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://onesignal.com/api/v1/notifications");
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json', $one_signal_rest_auth_key));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
        curl_setopt($ch, CURLOPT_HEADER, FALSE);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
        
        $response = curl_exec($ch);
        
        $logger->addInfo('Push send for job ' . $job_id . ' curl answer', [$response]);

        curl_close($ch);
    }

    /**
     * Get Potetial Translators
     * @param Job $job
     * @return User[]
     */
    public function getPotentialTranslators(Job $job)
    {
        $translator_type_enum = TranslatorTypeEnum::tryFrom($job->job_type);
        $translator_type = $translator_type_enum->translatorType();

        $joblanguage = $job->from_language_id;
        $gender = $job->gender;
        $translator_level = [];

        if (!empty($job->certified)) {
            $translator_level_enum = TranslatorLevelEnum::tryFrom($job->certified);
            $translator_level = array_merge($translator_level, $translator_level_enum->levels());

            if ($job->certified == null) {
                $translator_level[] = 'Certified';
                $translator_level[] = 'Certified with specialisation in law';
                $translator_level[] = 'Certified with specialisation in health care';
                $translator_level[] = 'Layman';
                $translator_level[] = 'Read Translation courses';
            }
        }

        $blacklist = UsersBlacklist::where('user_id', $job->user_id)->get();
        $translators_id = $blacklist->pluck('translator_id')->all();

        $users = User::getPotentialUsers($translator_type, $joblanguage, $gender, $translator_level, $translators_id);

        return $users;
    }

    /**
     * Update Job 
     * @param int $id
     * @param array $data
     * @return mixed
     */
    public function updateJob($id, $data, $cuser)
    {
        $job = Job::find($id);

        $current_translator = $job->translatorJobRel->whereNull('cancel_at')->first();

        if (!$current_translator) {
            $current_translator = $job->translatorJobRel->whereNotNull('completed_at')->first();
        }

        $log_data = [];
        $lang_changed = false;

        $change_translator = $this->changeTranslator($current_translator, $data, $job);

        if ($change_translator['translatorChanged']) { 
            $log_data[] = $change_translator['log_data'];
        }

        $change_due = $this->changeDue($job->due, $data['due']);
        
        if ($change_due['dateChanged']) {
            $old_time = $job->due;
            $job->due = $data['due'];
            $log_data[] = $change_due['log_data'];
        }

        if ($job->from_language_id != $data['from_language_id']) {
            $log_data[] = [
                'old_lang' => TeHelper::fetchLanguageFromJobId($job->from_language_id),
                'new_lang' => TeHelper::fetchLanguageFromJobId($data['from_language_id'])
            ];

            $old_lang = $job->from_language_id;
            $job->from_language_id = $data['from_language_id'];
            $lang_changed = true;
        }

        $change_status = $this->changeStatus($job, $data, $change_translator['translatorChanged']);
        if ($change_status['statusChanged']) {
            $log_data[] = $change_status['log_data'];
        }

        $job->admin_comments = $data['admin_comments'];

        $this->logger->addInfo('USER #' . $cuser->id . '(' . $cuser->name . ')' . ' has been updated booking <a class="openjob" href="/admin/jobs/' . $id . '">#' . $id . '</a> with data:  ', $log_data);

        $job->reference = $data['reference'];

        if ($job->due <= Carbon::now()) {
            $job->save();

            return ['Updated'];
        } else {
            $job->save();

            if ($change_due['dateChanged']) {
                $this->sendChangedDateNotification($job, $old_time);
            }

            if ($change_translator['translatorChanged']) {
                $this->sendChangedTranslatorNotification($job, $current_translator, $change_translator['new_translator']);
            }

            if ($lang_changed) {
                $this->sendChangedLangNotification($job, $old_lang);
            }
        }
    }

    /**
     * Change Status 
     * @param Job $job
     * @param array $data
     * @param $changed_translator
     * @return array
     */
    private function changeStatus($job, $data, $changed_translator)
    {
        $old_status = $job->status;
        $status_changed = false;

        if ($old_status != $data['status']) {
            switch ($job->status) {
                case 'timedout':
                    $status_changed = $this->changeTimedoutStatus($job, $data, $changed_translator);
                    break;
                case 'completed':
                    $status_changed = $this->changeCompletedStatus($job, $data);
                    break;
                case 'started':
                    $status_changed = $this->changeStartedStatus($job, $data);
                    break;
                case 'pending':
                    $status_changed = $this->changePendingStatus($job, $data, $changed_translator);
                    break;
                case 'withdrawafter24':
                    $status_changed = $this->changeWithdrawafter24Status($job, $data);
                    break;
                case 'assigned':
                    $status_changed = $this->changeAssignedStatus($job, $data);
                    break;
                default:
                    $status_changed = false;
                    break;
            }

            if ($status_changed) {
                $log_data['old_status'] = $old_status;
                $log_data['new_status'] = $data['status'];
                
                $status_changed = true;

                return ['statusChanged' => $status_changed, 'log_data' => $log_data];
            }
        }
    }

    /**
     * Change timedout status
     * @param Job $job
     * @param array $data
     * @param $changed_translator
     * @return bool
     */
    private function changeTimedoutStatus($job, $data, $changed_translator)
    {
        $job->status = $data['status'];

        $user = $job->user()->first();
        
        $email = (!empty($job->user_email)) ?  $job->user_email : $user->email;
        $name = $user->name;

        $data_email['user'] = $user;
        $data_email['job'] = $job;
        
        if ($data['status'] == 'pending') {
            $job->created_at = Carbon::now()->format('Y-m-d H:i:s');
            $job->emailsent = 0;
            $job->emailsenttovirpal = 0;

            $job->save();

            $job_data = $this->jobToData($job);

            $subject = 'Vi har nu återöppnat er bokning av ' . TeHelper::fetchLanguageFromJobId($job->from_language_id) . 'tolk för bokning #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.job-change-status-to-customer', $data_email);

            $this->sendNotificationTranslator($job, $job_data, '*');   // send Push all suitable translators

            return true;
        } elseif ($changed_translator) {
            $job->save();

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data_email);

            return true;
        }

        return false;
    }

    /**
     * Changed Completed Status
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeCompletedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['status'] == 'timedout') {
            if ($data['admin_comments'] == '') {
                return false;
            }

            $job->admin_comments = $data['admin_comments'];
        }
        
        $job->save();

        return true;
    }

    /**
     * Change Started Status
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeStartedStatus($job, $data)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '') {
            return false;
        } 

        $job->admin_comments = $data['admin_comments'];

        if ($data['status'] == 'completed') {
            $user = $job->user()->first();

            if ($data['sesion_time'] == '') { 
                return false;
            }

            $interval = $data['sesion_time'];
            $job->end_at = Carbon::now()->format('Y-m-d H:i:s');
            $job->session_time = $interval;
            
            $diff = explode(':', $interval);
            $session_time = $diff[0] . ' tim ' . $diff[1] . ' min';

            $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
            $name = $user->name;
            
            $data_email['user'] = $user;
            $data_email['job'] = $job;
            $data_email['session_time'] = $session_time;
            $data_email['for_text'] = 'faktura';
            
            $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data_email);

            $user = $job->translatorJobRel->where('completed_at', Null)->where('cancel_at', Null)->first();

            $email = $user->user->email;
            $name = $user->user->name;
            $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
            
            $data_email['user'] = $user;
            $data_email['job'] = $job;
            $data_email['session_time'] = $session_time;
            $data_email['for_text'] = 'lön';

            $this->mailer->send($email, $name, $subject, 'emails.session-ended', $data_email);
        }

        $job->save();

        return true;
    }

    /**
     * Change Pending Status
     * @param Job $job
     * @param array $data
     * @param $changedTranslator
     * @return bool
     */
    private function changePendingStatus($job, $data, $changedTranslator)
    {
        $job->status = $data['status'];

        if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
            return false;
        } 

        $job->admin_comments = $data['admin_comments'];

        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ?  $job->user_email : $user->email;
        $name = $user->name;
        
        $data_email['user'] = $user;
        $data_email['job'] = $job;

        if ($data['status'] == 'assigned' && $changedTranslator) {
            $job->save();

            $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
            $this->mailer->send($email, $name, $subject, 'emails.job-accepted', $data_email);

            $translator = Job::getJobsAssignedTranslatorDetail($job);
            $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-translator-new-translator', $data_email);

            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

            $this->sendSessionStartRemindNotification($user, $job, $language, $job->due, $job->duration);
            $this->sendSessionStartRemindNotification($translator, $job, $language, $job->due, $job->duration);

            return true;
        } else {
            $subject = 'Avbokning av bokningsnr: #' . $job->id;
            $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $data_email);

            $job->save();

            return true;
        }

        return false;
    }

    /**
     * TODO remove method and add service for notification
     * TEMP method
     * send session start remind notification
     * 
     * @param User $user
     * @param Job $job 
     * @param string $language 
     * @param string $duration
     * @return void
     */
    public function sendSessionStartRemindNotification($user, $job, $language, $due, $duration)
    {
        $this->logger->pushHandler(new StreamHandler(storage_path('logs/cron/laravel-' . date('Y-m-d') . '.log'), Logger::DEBUG));
        $this->logger->pushHandler(new FirePHPHandler());

        $data = array();

        $data['notification_type'] = 'session_start_remind';
        $due_explode = explode(' ', $due);

        
        $msg_text = ($job->customer_physical_type == 'yes') 
            ? ["en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (på plats i ' . $job->town . ') kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min. Lycka till och kom ihåg att ge feedback efter utförd tolkning!'] 
            : ["en" => 'Detta är en påminnelse om att du har en ' . $language . 'tolkning (telefon) kl ' . $due_explode[1] . ' på ' . $due_explode[0] . ' som vara i ' . $duration . ' min.Lycka till och kom ihåg att ge feedback efter utförd tolkning!'];
        
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);

            $is_need_to_delay = $this->isNeedToDelayPush($user->id);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $is_need_to_delay);
            $this->logger->addInfo('sendSessionStartRemindNotification ', ['job' => $job->id]);
        }
    }

    /**
     * Change withdraw after 24 status
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeWithdrawafter24Status($job, $data)
    {
        if (in_array($data['status'], ['timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '') {
                return false;
            } 

            $job->admin_comments = $data['admin_comments'];
            $job->save();

            return true;
        }
        
        return false;
    }

    /**
     * Change Assigned Status
     * @param Job $job
     * @param array $data
     * @return bool
     */
    private function changeAssignedStatus($job, $data)
    {
        if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24', 'timedout'])) {
            $job->status = $data['status'];

            if ($data['admin_comments'] == '' && $data['status'] == 'timedout') {
                return false;
            } 

            $job->admin_comments = $data['admin_comments'];

            if (in_array($data['status'], ['withdrawbefore24', 'withdrawafter24'])) {
                $user = $job->user()->first();

                $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
                $name = $user->name;

                $data_email['user'] = $user;
                $data_email['job'] = $job;

                $subject = 'Information om avslutad tolkning för bokningsnummer #' . $job->id;
                $this->mailer->send($email, $name, $subject, 'emails.status-changed-from-pending-or-assigned-customer', $data_email);

                $user = $job->translatorJobRel->where('completed_at', Null)
                    ->where('cancel_at', Null)
                    ->first();

                $email = $user->user->email;
                $name = $user->user->name;
                $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;

                $data_email['user'] = $user;
                $data_email['job'] = $job;

                $this->mailer->send($email, $name, $subject, 'emails.job-cancel-translator', $data_email);
            }

            $job->save();

            return true;
        }

        return false;
    }

    /**
     * Change Translator
     * @param $current_translator
     * @param array $data
     * @param Job $job
     * @return array
     */
    private function changeTranslator($current_translator, $data, $job)
    {
        $translator_changed = false;

        if ($current_translator || (($data['translator'] ?? 0) != 0) || $data['translator_email'] != '') {
            $log_data = [];

            if (
                $current_translator 
                && (($data['translator'] ?? false) != $current_translator->user_id || $data['translator_email'] != '') 
                && (($data['translator'] ?? 0) != 0)
            ) {
                if ($data['translator_email'] != '') { 
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }

                $new_translator = $current_translator->toArray();
                $new_translator['user_id'] = $data['translator'];

                unset($new_translator['id']);

                $new_translator = Translator::create($new_translator);
                $current_translator->cancel_at = Carbon::now();

                $current_translator->save();

                $log_data['old_translator'] = $current_translator->user->email;
                $log_data['new_translator'] = $new_translator->user->email;

                $translator_changed = true;
            } elseif (is_null($current_translator) && (($data['translator'] ?? 0) != 0 || $data['translator_email'] != '')) {
                if ($data['translator_email'] != '') {
                    $data['translator'] = User::where('email', $data['translator_email'])->first()->id;
                }

                $new_translator = Translator::create([
                    'user_id' => $data['translator'], 
                    'job_id' => $job->id
                ]);

                $log_data['old_translator'] = null;
                $log_data['new_translator'] = $new_translator->user->email;

                $translator_changed = true;
            }

            if ($translator_changed) {
                return [
                    'translatorChanged' => $translator_changed, 
                    'new_translator' => $new_translator, 
                    'log_data' => $log_data
                ];
            }
        }

        return ['translatorChanged' => $translator_changed];
    }

    /**
     * Change Due 
     * @param string $old_due
     * @param string $new_due
     * @return array
     */
    private function changeDue($old_due, $new_due)
    {
        $date_changed = false;

        if ($old_due != $new_due) {
            $log_data['old_due'] = $old_due;
            $log_data['new_due'] = $new_due;
            
            $date_changed = true;

            return [
                'dateChanged' => $date_changed, 
                'log_data' => $log_data
            ];
        }

        return ['dateChanged' => $date_changed];
    }

    /**
     * Send Changed Translator Notification
     * @param Job $job
     * @param $current_translator
     * @param $new_translator
     * @return void
     */
    public function sendChangedTranslatorNotification($job, $current_translator = null, $new_translator)
    {
        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om tilldelning av tolkuppdrag för uppdrag # ' . $job->id . ')';
        
        $data['user'] = $user;
        $data['job'] = $job;
        
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-customer', $data);

        if ($current_translator) {
            $user = $current_translator->user;
            $name = $user->name;
            $email = $user->email;
            $data['user'] = $user;

            $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-old-translator', $data);

            // Does this intend to notify both ?
        }

        $user = $new_translator->user;
        $name = $user->name;
        $email = $user->email;
        $data['user'] = $user;

        $this->mailer->send($email, $name, $subject, 'emails.job-changed-translator-new-translator', $data);
    }

    /**
     * Send Changed Date Notification
     * @param Job $job
     * @param string $old_time
     * @return void
     */
    public function sendChangedDateNotification($job, $old_time)
    {
        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        
        $data['user'] = $user;
        $data['job'] = $job;
        $data['old_time'] = $old_time;
        
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-date', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $data['user'] = $translator;
        $data['job'] = $job;
        $data['old_time'] = $old_time;
        
        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Send Changed Lang Notification
     * @param Job $job
     * @param $old_lang
     * @return void
     */
    public function sendChangedLangNotification($job, $old_lang)
    {
        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Meddelande om ändring av tolkbokning för uppdrag # ' . $job->id . '';
        
        $data['user'] = $user;
        $data['job'] = $job;
        $data['old_lang'] = $old_lang;
        
        $this->mailer->send($email, $name, $subject, 'emails.job-changed-lang', $data);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        $this->mailer->send($translator->email, $translator->name, $subject, 'emails.job-changed-date', $data);
    }

    /**
     * Function to send Job Expired Push Notification
     * @param $job
     * @param $user
     * @return void 
     */
    public function sendExpiredNotification($job, $user)
    {
        $data = array();
        $data['notification_type'] = 'job_expired';

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        $msg_text = [
            "en" => 'Tyvärr har ingen tolk accepterat er bokning: (' . $language . ', ' . $job->duration . 'min, ' . $job->due . '). Vänligen pröva boka om tiden.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);

            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
    }

    /**
     * Function to send the notification for sending the admin job cancel
     * @param int $job_id
     * @return void
     */
    public function sendNotificationByAdminCancelJob($job_id)
    {
        $job = Job::findOrFail($job_id);

        $user_meta = $job->user->userMeta()->first();

        // save job's information to data for sending Push
        $data = $job->only([ 
            'job_id',
            'from_language_id',
            'immediate',
            'duration',
            'status',
            'gender',
            'certified',
            'due',
            'job_type',
            'customer_phone_type',
            'customer_physical_type',
        ]);

        $data['customer_town'] = $user_meta->city;
        $data['customer_type'] = $user_meta->customer_type;

        $due_date = explode(" ", $job->due);

        $data['due_date'] = $due_date[0];
        $data['due_time'] = $due_date[1];

        $data['job_for'] = array();

        if ($job->gender != null) {
            if ($job->gender == 'male') {
                $data['job_for'][] = 'Man';
            } else if ($job->gender == 'female') {
                $data['job_for'][] = 'Kvinna';
            }
        }
        if ($job->certified != null) {
            if ($job->certified == 'both') {
                $data['job_for'][] = 'normal';
                $data['job_for'][] = 'certified';
            } else if ($job->certified == 'yes') {
                $data['job_for'][] = 'certified';
            } else {
                $data['job_for'][] = $job->certified;
            }
        }

        $this->sendNotificationTranslator($job, $data, '*');   // send Push all sutiable translators
    }

    /**
     * Send session start remind notification
     * @param User $user
     * @param Job $job
     * @param $language
     * @param $due
     * @param $duration
     * @return void
     */
    private function sendNotificationChangePending($user, $job, $language, $due, $duration)
    {
        $data = array();
        $data['notification_type'] = 'session_start_remind';

        $msg_text = ($job->customer_physical_type == 'yes') 
            ? ["en" => 'Du har nu fått platstolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!']
            : ["en" => 'Du har nu fått telefontolkningen för ' . $language . ' kl ' . $duration . ' den ' . $due . '. Vänligen säkerställ att du är förberedd för den tiden. Tack!'];
        
        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);

            $isNeedToDelayPush = $this->isNeedToDelayPush($user->id);
            $this->sendPushNotificationToSpecificUsers($users_array, $job->id, $data, $msg_text, $isNeedToDelayPush);
        }
    }

    /**
     * Making user_tags string from users array for creating onesignal notifications
     * @param $users
     * @return string
     */
    private function getUserTagsStringFromArray($users)
    {
        $user_tags = "[";
        $first = true;

        foreach ($users as $one_user) {
            if ($first) {
                $first = false;
            } else {
                $user_tags .= ',{"operator": "OR"},';
            }

            $user_tags .= '{"key": "email", "relation": "=", "value": "' . strtolower($one_user->email) . '"}';
        }

        $user_tags .= ']';
        
        return $user_tags;
    }

    /**
     * Accept Job 
     * @param $data
     * @param $user
     */
    public function acceptJob($data, $user)
    {
        $cuser = $user;
        $job_id = $data['job_id'];
        $job = Job::findOrFail($job_id);

        if (!Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            if ($job->status == 'pending' && Job::insertTranslatorJobRel($cuser->id, $job_id)) {
                $job->status = 'assigned';
                $job->save();

                $user = $job->user()->first();
                $mailer = new AppMailer();

                $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
                $name = (!empty($job->user_email)) ? $user->name : $user->name;
                $subject = (!empty($job->user_email)) ? 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')' : 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';

                $data['user'] = $user;
                $data['job'] = $job;
                
                $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);
            }

            /**
             * @todo add flash message here.
             */
            $jobs = $this->getPotentialJobs($cuser);
            
            $response = array();
            $response['list'] = json_encode(['jobs' => $jobs, 'job' => $job], true);
            $response['status'] = 'success';
        } else {
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden! Bokningen är inte accepterad.';
        }

        return $response;
    }

    /**
     * Function to accept the job with the job id
     * @param int $job_id 
     * @param $cuser
     * @return array
     */
    public function acceptJobWithId($job_id, $cuser)
    {
        $job = Job::findOrFail($job_id);
        $response = array();

        if (Job::isTranslatorAlreadyBooked($job_id, $cuser->id, $job->due)) {
            // You already have a booking time
            $response['status'] = 'fail';
            $response['message'] = 'Du har redan en bokning den tiden ' . $job->due . '. Du har inte fått denna tolkning';

            return $response;
        }

        if ($job->status != 'pending' || !Job::insertTranslatorJobRel($cuser->id, $job_id)) {
            // Booking already accepted by someone else
            $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
            $response['status'] = 'fail';
            $response['message'] = 'Denna ' . $language . 'tolkning ' . $job->duration . 'min ' . $job->due . ' har redan accepterats av annan tolk. Du har inte fått denna tolkning';

            return $response;
        }

        $job->status = 'assigned';
        $job->save();

        $user = $job->user()->first();
        $mailer = new AppMailer();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = (!empty($job->user_email)) ? $user->name : $user->name;
        
        $subject = 'Bekräftelse - tolk har accepterat er bokning (bokning # ' . $job->id . ')';
        
        $data['user'] = $user;
        $data['job']  = $job;
        
        $mailer->send($email, $name, $subject, 'emails.job-accepted', $data);

        $data = array();
        $data['notification_type'] = 'job_accepted';

        $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);

        $msg_text = [
            "en" => 'Din bokning för ' . $language . ' translators, ' . $job->duration . 'min, ' . $job->due . ' har accepterats av en tolk. Vänligen öppna appen för att se detaljer om tolken.'
        ];

        if ($this->isNeedToSendPush($user->id)) {
            $users_array = array($user);

            $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($user->id));
        }
        // Your Booking is accepted sucessfully
        $response['status'] = 'success';
        $response['list']['job'] = $job;
        $response['message'] = 'Du har nu accepterat och fått bokningen för ' . $language . 'tolk ' . $job->duration . 'min ' . $job->due;
        
        return $response;
    }

    /**
     * Cancel Job Ajax
     * @param array $data 
     * @param User $user
     * @return array
     */
    public function cancelJobAjax($data, $user)
    {
        $response = array();
        /**
         * @todo 
         * add 24hrs loging here. 
         * If the cancelation is before 24 hours before the booking tie - supplier will be informed. Flow ended
         * if the cancelation is within 24
         * if cancelation is within 24 hours - translator will be informed AND the customer will get an addition to his number of bookings - so we will charge of it if the cancelation is within 24 hours
         * so we must treat it as if it was an executed session
         */
        $cuser = $user;
        $job_id = $data['job_id'];
        
        $job = Job::findOrFail($job_id);

        $translator = Job::getJobsAssignedTranslatorDetail($job);

        if ($cuser->is('customer')) {
            $job->withdraw_at = Carbon::now();
            
            $job->status = ($job->withdraw_at->diffInHours($job->due) >= 24) ? 'withdrawbefore24' : 'withdrawafter24';
            $response['jobstatus'] = 'success';

            $job->save();

            Event::fire(new JobWasCanceled($job));

            $response['status'] = 'success';
            
            if ($translator) {
                $data = array();
                $data['notification_type'] = 'job_cancelled';
                
                $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                $msg_text = [
                    "en" => 'Kunden har avbokat bokningen för ' . $language . 'tolk, ' . $job->duration . 'min, ' . $job->due . '. Var god och kolla dina tidigare bokningar för detaljer.'
                ];

                if ($this->isNeedToSendPush($translator->id)) {
                    $users_array = array($translator);
                    // send Session Cancel Push to Translaotor
                    $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($translator->id)); 
                }
            }
        } else {
            if ($job->due->diffInHours(Carbon::now()) > 24) {

                $customer = $job->user()->first();

                if ($customer) {
                    $data = array();
                    $data['notification_type'] = 'job_cancelled';

                    $language = TeHelper::fetchLanguageFromJobId($job->from_language_id);
                    $msg_text = [
                        "en" => 'Er ' . $language . 'tolk, ' . $job->duration . 'min ' . $job->due . ', har avbokat tolkningen. Vi letar nu efter en ny tolk som kan ersätta denne. Tack.'
                    ];

                    if ($this->isNeedToSendPush($customer->id)) {
                        $users_array = array($customer);

                        $this->sendPushNotificationToSpecificUsers($users_array, $job_id, $data, $msg_text, $this->isNeedToDelayPush($customer->id));     // send Session Cancel Push to customer
                    }
                }

                $job->status = 'pending';
                $job->created_at = date('Y-m-d H:i:s');
                $job->will_expire_at = TeHelper::willExpireAt($job->due, date('Y-m-d H:i:s'));

                $job->save();

                Job::deleteTranslatorJobRel($translator->id, $job_id);

                $data = $this->jobToData($job);

                $this->sendNotificationTranslator($job, $data, $translator->id);   // send Push all sutiable translators

                $response['status'] = 'success';
            } else {
                $response['status'] = 'fail';
                $response['message'] = 'Du kan inte avboka en bokning som sker inom 24 timmar genom DigitalTolk. Vänligen ring på +46 73 75 86 865 och gör din avbokning over telefon. Tack!';
            }
        }

        return $response;
    }

    /**
     * Function to get the potential jobs for paid,rws,unpaid translators
     * @param User $cuser
     * @return array
     */
    public function getPotentialJobs($cuser)
    {
        $cuser_meta = $cuser->userMeta;
        $job_type = 'unpaid';
        $translator_type = $cuser_meta->translator_type;
        
        $translator_type_enum = TranslatorTypeEnum::tryFrom($translator_type);
        $job_type = $translator_type_enum->type();

        $languages = UserLanguages::where('user_id', '=', $cuser->id)->get();
        $userlanguage = $languages->pluck('lang_id')->all();

        $gender = $cuser_meta->gender;
        $translator_level = $cuser_meta->translator_level;
        // Call the town function for checking if the job physical, then translators in one town can get job
        $job_ids = Job::getJobs($cuser->id, $job_type, 'pending', $userlanguage, $gender, $translator_level);

        foreach ($job_ids as $k => $job) {
            $job_user_id = $job->user_id;
            $job->specific_job = Job::assignedToPaticularTranslator($cuser->id, $job->id);
            $job->check_particular_job = Job::checkParticularJob($cuser->id, $job);
            
            $checktown = Job::checkTowns($job_user_id, $cuser->id);

            if($job->specific_job == 'SpecificJob') {
                if ($job->check_particular_job == 'userCanNotAcceptJob') {
                    unset($job_ids[$k]);
                }
            }

            if (in_array($job->customer_phone_type, ['no', '']) && $job->customer_physical_type == 'yes' && $checktown == false) {
                unset($job_ids[$k]);
            }
        }

        return $job_ids;
    }

    /**
     * End Job 
     * @param array $post_data
     * @return array
     */
    public function endJob($post_data)
    {
        $completed_date = Carbon::now()->format('Y-m-d H:i:s');

        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        if($job_detail->status != 'started') {
            return ['status' => 'success'];
        }

        $duedate = $job_detail->due;
        $start = Carbon::parse($duedate);
        $end = Carbon::parse($completed_date);
        $diff = $end->diff($start);
        $interval = $diff->format('%h:%i:%s');

        $job = $job_detail;
        $job->end_at = Carbon::now()->format('Y-m-d H:i:s');
        $job->status = 'completed';
        $job->session_time = $interval;

        $user = $job->user()->first();

        $email = (!empty($job->user_email)) ? $job->user_email : $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        $session_explode = explode(':', $job->session_time);
        $session_time = $session_explode[0] . ' tim ' . $session_explode[1] . ' min';

        $data['user'] = $user;
        $data['job'] = $job;
        $data['session_time'] = $session_time;
        $data['for_text'] = 'faktura';
        
        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();

        Event::fire(new SessionEnded($job, ($post_data['user_id'] == $job->user_id) ? $tr->user_id : $job->user_id));

        $user = $tr->user()->first();
        $email = $user->email;
        $name = $user->name;
        $subject = 'Information om avslutad tolkning för bokningsnummer # ' . $job->id;
        
        $data['user'] = $user;
        $data['job'] = $job;
        $data['session_time'] = $session_time;
        $data['for_text'] = 'lön';

        $mailer = new AppMailer();
        $mailer->send($email, $name, $subject, 'emails.session-ended', $data);

        $tr->completed_at = $completed_date;
        $tr->completed_by = $post_data['user_id'];
        $tr->save();

        $response['status'] = 'success';

        return $response;
    }

    /**
     * Customer Not Call
     * @param array $post_data
     * @return array
     */
    public function customerNotCall($post_data)
    {
        $completed_date = Carbon::now()->format('Y-m-d H:i:s');

        $job_id = $post_data["job_id"];
        $job_detail = Job::with('translatorJobRel')->find($job_id);

        $job = $job_detail;
        $job->end_at = Carbon::now()->format('Y-m-d H:i:s');
        $job->status = 'not_carried_out_customer';
        
        $job->save();

        $tr = $job->translatorJobRel()->where('completed_at', Null)->where('cancel_at', Null)->first();
        $tr->completed_at = $completed_date;
        $tr->completed_by = $tr->user_id;
        
        $tr->save();

        $response['status'] = 'success';
        
        return $response;
    }

    /**
     * Get All
     * @param Request $request 
     * @param int $limit 
     * @return mixed
     */
    public function getAll(Request $request, $limit = null)
    {
        $requestdata = $request->all();
        $cuser = $request->user();

        if ($cuser && $cuser->user_type == env('SUPERADMIN_ROLE_ID')) {
            $all_jobs = Job::query();
            /**
             * ===============================================
             * SEE README.md (query scopes)
             * ===============================================
             */
            $all_jobs->filterSuperAdmin($requestdata);
            $all_jobs->orderBy('created_at', 'desc');
            $all_jobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            $all_jobs = ($limit == 'all') ? $all_jobs->get() : $all_jobs->paginate(15);
        } else {
            $all_jobs = Job::query();
            /**
             * ===============================================
             * SEE README.md (query scopes)
             * ===============================================
             */
            $all_jobs->filter($requestdata);
            $all_jobs->orderBy('created_at', 'desc');
            $all_jobs->with('user', 'language', 'feedback.user', 'translatorJobRel.user', 'distance');

            $all_jobs = ($limit == 'all') ? $all_jobs->get() : $all_jobs->paginate(15);
        }

        return $all_jobs;
    }

    /**
     * Alerts
     * @return array
     */
    public function alerts()
    {
        $jobs = Job::all();
        $ses_jobs = [];
        $job_id = [];
        $diff = [];
        $i = 0;

        foreach ($jobs as $job) {
            $session_time = explode(':', $job->session_time);

            if (count($session_time) >= 3) {
                $diff[$i] = ($session_time[0] * 60) + $session_time[1] + ($session_time[2] / 60);

                if ($diff[$i] >= $job->duration && $diff[$i] >= $job->duration * 2) {
                    $ses_jobs [$i] = $job;
                }

                $i++;
            }
        }

        $job_id = collect($ses_jobs)->pluck('id')->all();

        $languages = Language::where('active', '1')->orderBy('language')->get();
        
        $requestdata = Request::all();

        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();

        if ($cuser && $cuser->is('superadmin')) {
            $all_jobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->whereIn('jobs.id', $job_id);
            /**
             * ===============================================
             * SEE README.md (query scopes)
             * ===============================================
             */
            $all_jobs->filterSuperAdmin($requestdata);

            $all_jobs->select('jobs.*', 'languages.language')
                ->where('jobs.ignore', 0)
                ->whereIn('jobs.id', $job_id);

            $all_jobs->orderBy('jobs.created_at', 'desc');
            $all_jobs = $all_jobs->paginate(15);
        }

        return [
            'allJobs' => $all_jobs, 
            'languages' => $languages, 
            'all_customers' => $all_customers, 
            'all_translators' => $all_translators, 
            'requestdata' => $requestdata
        ];
    }

    /**
     * User Login Failed
     * @return array
     */
    public function userLoginFailed()
    {
        $throttles = Throttles::where('ignore', 0)->with('user')->paginate(15);

        return ['throttles' => $throttles];
    }

    /**
     * Booking expired no accepted
     * @return array
     */
    public function bookingExpireNoAccepted()
    {
        $languages = Language::where('active', '1')->orderBy('language')->get();
        $requestdata = Request::all();

        $all_customers = DB::table('users')->where('user_type', '1')->lists('email');
        $all_translators = DB::table('users')->where('user_type', '2')->lists('email');

        $cuser = Auth::user();

        if ($cuser && ($cuser->is('superadmin') || $cuser->is('admin'))) {
            $all_jobs = DB::table('jobs')
                ->join('languages', 'jobs.from_language_id', '=', 'languages.id')
                ->where('jobs.ignore_expired', 0);
            /**
             * ===============================================
             * SEE README.md (query scopes)
             * ===============================================
             */
            $all_jobs->filterSuperAdmin($requestdata);

            $all_jobs->select('jobs.*', 'languages.language')
                ->where('jobs.status', 'pending')
                ->where('ignore_expired', 0)
                ->where('jobs.due', '>=', Carbon::now());

            $all_jobs->orderBy('jobs.created_at', 'desc');
            $all_jobs = $all_jobs->paginate(15);
        }

        return [
            'allJobs' => $all_jobs, 
            'languages' => $languages, 
            'all_customers' => $all_customers, 
            'all_translators' => $all_translators, 
            'requestdata' => $requestdata
        ];
    }

    /** 
     * Ignore Expiring
     * @param int $id
     * @return array
     */
    public function ignoreExpiring($id)
    {
        $job = Job::find($id);
        $job->ignore = 1;
        $job->save();

        return ['success', 'Changes saved'];
    }

    /** 
     * Ignore Expired
     * @param int $id
     * @return array
     */
    public function ignoreExpired($id)
    {
        $job = Job::find($id);
        $job->ignore_expired = 1;
        $job->save();

        return ['success', 'Changes saved'];
    }

    /** 
     * Ignore throttle
     * @param int $id
     * @return array
     */
    public function ignoreThrottle($id)
    {
        $throttle = Throttles::find($id);
        $throttle->ignore = 1;
        $throttle->save();

        return ['success', 'Changes saved'];
    }

    /** 
     * Reopen 
     * @param Request $request
     * @return array
     */
    public function reopen($request)
    {
        $job_id = $request['jobid'];
        $userid = $request['userid'];

        $job = Job::find($job_id);
        $job = $job->toArray();

        $data = array();

        $data['created_at'] = Carbon::now()->format('Y-m-d H:i:s');
        $data['will_expire_at'] = TeHelper::willExpireAt($job['due'], $data['created_at']);
        $data['updated_at'] = date('Y-m-d H:i:s');
        $data['user_id'] = $userid;
        $data['job_id'] = $job_id;
        $data['cancel_at'] = Carbon::now();

        $datareopen = array();
        $datareopen['status'] = 'pending';
        $datareopen['created_at'] = Carbon::now();
        $datareopen['will_expire_at'] = TeHelper::willExpireAt($job['due'], $datareopen['created_at']);

        if ($job['status'] != 'timedout') {
            $affectedRows = Job::where('id', '=', $job_id)->update($datareopen);
            $new_jobid = $job_id;
        } else {
            $job['status'] = 'pending';
            $job['created_at'] = Carbon::now();
            $job['updated_at'] = Carbon::now();
            $job['will_expire_at'] = TeHelper::willExpireAt($job['due'], date('Y-m-d H:i:s'));
            $job['updated_at'] = date('Y-m-d H:i:s');
            $job['cust_16_hour_email'] = 0;
            $job['cust_48_hour_email'] = 0;
            $job['admin_comments'] = 'This booking is a reopening of booking #' . $job_id;
         
            $affectedRows = Job::create($job);
            $new_jobid = $affectedRows['id'];
        }
        
        Translator::where('job_id', $job_id)->where('cancel_at', NULL)->update(['cancel_at' => $data['cancel_at']]);

        Translator::create($data);
        
        if (isset($affectedRows)) {
            $this->sendNotificationByAdminCancelJob($new_jobid);
            
            return ["Tolk cancelled!"];
        }

        return ["Please try again!"];
    }

    /**
     * Convert number of minutes to hour and minute variant
     * @param  int $time   
     * @param  string $format 
     * @return string         
     */
    private function convertToHoursMins($time, $format = '%02dh %02dmin')
    {
        if ($time < 60) {
            return $time . 'min';
        } else if ($time == 60) {
            return '1h';
        }

        $hours = floor($time / 60);
        $minutes = ($time % 60);
        
        return sprintf($format, $hours, $minutes);
    }

}