<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use DTApi\Repository\UserRepository;

class UserRepositoryTest extends TestCase
{
    /**
     * Create new user
     */
    public function test_create_user()
    {
        $request['role'] = 'user'; // or ID
        $request['name'] = 'John Doe';
        $request['company_id'] = 1;
        $request['department_id'] = 1;
        $request['email'] = 'user@sample.com';
        $request['dob_or_orgid'] = '';
        $request['phone'] = '0123456789';
        $request['mobile'] = '0123456789';
        $request['password'] = 'password';

        $repo = new UserRepository;
        $user = $repo->createOrUpdate(null, $request);

        $this->assertTrue($user);
    }

    /**
     * Update existing user
     *
     * @return void
     */
    public function test_update_user()
    {
        $userId = 5;
        $request['role'] = 'customer'; // or ID
        $request['name'] = 'John Doe';
        $request['company_id'] = 1;
        $request['department_id'] = 1;
        $request['email'] = 'user@sample.com';
        $request['dob_or_orgid'] = '';
        $request['phone'] = '099999999';
        $request['mobile'] = '0123456789';
        $request['password'] = 'password';

        $repo = new UserRepository;
        $user = $repo->createOrUpdate($userId, $request);

        $this->assertTrue($user);
    }

    /**
     * Create New Company for User
     *
     * @return void
     */
    public function test_create_company_for_user()
    {
        $request['role'] = 'customer'; // or ID
        $request['name'] = 'John Doe';
        $request['company_id'] = '';
        $request['department_id'] = '';
        $request['consumer_type'] = 'paid';
        $request['email'] = 'user@sample.com';
        $request['dob_or_orgid'] = '';
        $request['phone'] = '099999999';
        $request['mobile'] = '0123456789';
        $request['password'] = 'password';
        $request['status'] = 1; // previously 0

        $repo = new UserRepository;
        $updated = $repo->createOrUpdate(null, $request);

        $this->assertNotEquals($request['company_id'], $updated->company_id);
    }

    /**
     * Update existing user
     *
     * @return void
     */
    public function test_update_user_enable_status()
    {
        $user = User::findOrFail(5); // status = 0

        $request['role'] = 'customer'; // or ID
        $request['name'] = 'John Doe';
        $request['company_id'] = 1;
        $request['department_id'] = 1;
        $request['email'] = 'user@sample.com';
        $request['dob_or_orgid'] = '';
        $request['phone'] = '099999999';
        $request['mobile'] = '0123456789';
        $request['password'] = 'password';
        $request['status'] = 1; // previously 0

        $repo = new UserRepository;
        $updated = $repo->createOrUpdate($user->id, $request);

        $this->assertNotEquals($updated->status, $user->status);
        $this->assertEquals($updated->status, 1);
    }

}