<?php

namespace DTApi\Http\Controllers;

use DTApi\Models\Job;
use DTApi\Models\Distance;
use Illuminate\Http\Request;
use DTApi\Repository\BookingRepository;
use DTApi\Requests\BookingStoreRequest;
use DTApi\Requests\BookingUpdateRequest;
use DTApi\Requests\BookingImmediateJobEmailRequest;
use DTApi\Requests\BookingHistoryRequest;
use DTApi\Requests\BookingAcceptRequest;
use DTApi\Requests\BookingAcceptJobRequest;
use DTApi\Requests\BookingCancelRequest;
use DTApi\Requests\BookingEndRequest;
use DTApi\Requests\BookingCustomerNotCallRequest;
use DTApi\Requests\BookingDistanceFeedRequest;
use DTApi\Requests\BookingReopenRequest;

/**
 * Class BookingController
 * @package DTApi\Http\Controllers
 */
class BookingController extends Controller
{
    /**
     * @var BookingRepository
     */
    protected $repository;

    /**
     * BookingController constructor.
     * @param BookingRepository $bookingRepository
     */
    public function __construct(BookingRepository $bookingRepository)
    {
        $this->repository = $bookingRepository;
    }

    /**
     * Get Job Records
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function index(Request $request)
    {
        if ($user_id = $request->get('user_id')) {
            $response = $this->repository->getUsersJobs($user_id);
        } elseif (in_array($request->user()->user_type, [env('ADMIN_ROLE_ID'), env('SUPERADMIN_ROLE_ID')])) {
            $response = $this->repository->getAll($request);
        }

        return response($response);
    }

    /**
     * Show Job
     * @param int $id
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function show($id)
    {
        $job = $this->repository->with('translatorJobRel.user')->find($id);

        return response($job);
    }

    /**
     * Immediate Job Email
     * @param BookingStoreRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function store(BookingStoreRequest $request)
    {
        $data = $request->all();
        
        $response = $this->repository->store($request->user(), $data);

        return response($response);
    }

    /**
     * Update Job
     * @param BookingUpdateRequest $request
     * @param int $id
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function update(BookingUpdateRequest $request, $id)
    {
        $data = $request->except(['_token', 'submit']);
        $cuser = $request->user();

        $response = $this->repository->updateJob($id, $data, $cuser);

        return response($response);
    }

    /**
     * Immediate Job Email
     * @param BookingImmediateJobEmailRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function immediateJobEmail(BookingImmediateJobEmailRequest $request)
    {
        $data = $request->all();
        
        $response = $this->repository->storeJobEmail($data);

        return response($response);
    }

    /**
     * Get History
     * @param BookingHistoryRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function getHistory(BookingHistoryRequest $request)
    {
        $user_id = $request->get('user_id');
        
        $response = $this->repository->getUsersJobsHistory($user_id, $request);

        return response($response);
    }

    /**
     * Resend Notifications
     * @param BookingAcceptRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function acceptJob(BookingAcceptRequest $request)
    {
        $data = $request->all();
        $user = $request->user();

        $response = $this->repository->acceptJob($data, $user);

        return response($response);
    }

    /**
     * Accept Job With ID
     * @param BookingAcceptJobRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function acceptJobWithId(BookingAcceptJobRequest $request)
    {
        $data = $request->get('job_id');
        $user = $request->user();

        $response = $this->repository->acceptJobWithId($data, $user);

        return response($response);
    }

    /**
     * Cancel Job
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function cancelJob(BookingCancelRequest $request)
    {
        $data = $request->all();
        $user = $request->user();

        $response = $this->repository->cancelJobAjax($data, $user);

        return response($response);
    }

    /**
     * End Job
     * @param BookingEndRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function endJob(BookingEndRequest $request)
    {
        $data = $request->all();

        $response = $this->repository->endJob($data);

        return response($response);
    }

    /**
     * Update Job Record 
     * @param BookingCustomerNotCallRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function customerNotCall(BookingCustomerNotCallRequest $request)
    {
        $data = $request->all();

        $response = $this->repository->customerNotCall($data);

        return response($response);
    }

    /**
     * Get Potential Jobs
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function getPotentialJobs(Request $request)
    {
        $user = $request->user();

        $response = $this->repository->getPotentialJobs($user);

        return response($response);
    }

    /**
     * Update Distance Feed
     * @param BookingDistanceFeedRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function distanceFeed(BookingDistanceFeedRequest $request)
    {
        $data = $request->all();

        $distance = $data['distance'];
        $time = $data['time'];
        $jobid = $data['jobid'];
        $session = $data['session_time'] ?? "";
        $flagged = ($data['flagged'] == 'true') ? 'yes' : 'no';
        $manually_handled = ($data['manually_handled'] == 'true') ? 'yes' : 'no';
        $by_admin = ($data['by_admin'] == 'true') ? 'yes' : 'no';
        $admincomment = $data['admincomment'] ?? "";
        
        if ($time || $distance) {
            Distance::where('job_id', '=', $jobid)->update(['distance' => $distance, 'time' => $time]);
        }

        if ($admincomment || $session || $flagged || $manually_handled || $by_admin) {
            Job::where('id', '=', $jobid)->update([
                'admin_comments' => $admincomment, 
                'flagged' => $flagged, 
                'session_time' => $session, 
                'manually_handled' => $manually_handled, 
                'by_admin' => $by_admin
            ]);
        }

        return response('Record updated!');
    }

    /**
     * Reopen
     * @param BookingReopenRequest $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function reopen(BookingReopenRequest $request)
    {
        $data = $request->all();

        $response = $this->repository->reopen($data);

        return response($response);
    }

    /**
     * Resend Notifications
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->findOrFail($data['jobid']);
        $job_data = $this->repository->jobToData($job);
        
        $this->repository->sendNotificationTranslator($job, $job_data, '*');

        return response(['success' => 'Push sent']);
    }

    /**
     * Sends SMS to Translator
     * @param Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Symfony\Component\HttpFoundation\Response
     */
    public function resendSMSNotifications(Request $request)
    {
        $data = $request->all();
        $job = $this->repository->findOrFail($data['jobid']);
        
        try {
            $this->repository->sendSMSNotificationToTranslator($job);
        } catch (\Exception $e) {
            return response(['fail' => $e->getMessage()]);
        }

        return response(['success' => 'SMS sent']);
    }

}
