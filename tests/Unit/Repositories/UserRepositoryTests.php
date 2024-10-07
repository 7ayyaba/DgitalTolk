<?php
namespace DTApi\Unit\Repositories;


use PHPUnit\Framework\TestCase;
use DTApi\Models\User;
use DTApi\Models\Company;
use DTApi\Models\Department;
use DTApi\Models\UserMeta;
use DTApi\Repository\UserRepository;
use DTApi\Constants\Constants;

use Illuminate\Support\Facades\Hash;

class UserrepsitoryTests extends TestCase
{

    protected $repository;
    protected $model;

    protected function setUp(): void
    {
        parent::setUp();
        $this->model = new User();
        $this->repository = new UserRepository($this->model);

    }

    public function testCreateNewUser()
    {
        $request = [
            'role' => 'admin',
            'name' => 'Test User',
            'company_id' => '',
            'department_id' => '',
            'email' => 'test.user@gmailtest.com',
            'dob_or_orgid' => '1999-01-01',
            'phone' => '123456789',
            'mobile' => '987654321',
            'password' => 'password123',
            'consumer_type' => 'regular',
            'customer_type' => 'standard',
            'username' => 'test_user',
            'post_code' => '12345',
            'address' => '123 Test Street',
            'city' => 'TestCity',
            'town' => 'TestTown',
            'country' => 'TestCountry',
            'status' => '1',
        ];

        $user = $this->repository->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('admin', $user->user_type);
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function testUpdateExistingUser()
    {
        $existingUser = User::factory()->create([
            'user_type' => 'admin',
            'name' => 'Old Name',
            'company_id' => 1,
            'department_id' => 1,
            'email' => 'old.user@gmailtest.com',
            'phone' => '11111111',
        ]);

        $request = [
            'role' => 'admin',
            'name' => 'New Name',
            'company_id' => '2',
            'department_id' => '2',
            'email' => 'new.user@gmailtest.com',
            'password' => 'newpassword123',
            'status' => '1',
        ];

        $user = $this->repository->createOrUpdate($existingUser->id, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertEquals('New Name', $user->name);
        $this->assertEquals('2', $user->company_id);
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function testCreateCustomerWithPaidConsumerType()
    {
        $request = [
            'role' => Constants::CUSTOMER_ROLE_ID,
            'name' => 'Customer Name',
            'consumer_type' => 'paid',
            'company_id' => '',
            'department_id' => '',
        ];

        $user = $this->repository->createOrUpdate(null, $request);

        $this->assertInstanceOf(User::class, $user);
        $this->assertNotNull($user->company_id);
        $this->assertNotNull($user->department_id);

        $company = Company::find($user->company_id);
        $this->assertEquals('Customer Name', $company->name);

        $department = Department::find($user->department_id);
        $this->assertEquals('Customer Name', $department->name);
    }

    public function testUpdateTranslatorMeta()
    {
        $translatorUser = User::factory()->create(['user_type' => Constants::TRANSLATOR_ROLE_ID]);
        $request = [
            'role' => Constants::TRANSLATOR_ROLE_ID,
            'translator_type' => 'certified',
            'worked_for' => 'yes',
            'organization_number' => '12345678',
            'gender' => 'male',
            'translator_level' => 'senior',
        ];

        $user = $this->repository->createOrUpdate($translatorUser->id, $request);

        $this->assertInstanceOf(User::class, $user);
        $userMeta = UserMeta::where('user_id', $user->id)->first();
        $this->assertEquals('certified', $userMeta->translator_type);
        $this->assertEquals('yes', $userMeta->worked_for);
        $this->assertEquals('12345678', $userMeta->organization_number);
    }

    public function testUserStatusEnableDisable()
    {
        $user = User::factory()->create(['status' => '0']);
        $request = ['role' => 'admin', 'status' => '1'];

        $result = $this->repository->createOrUpdate($user->id, $request);

        $this->assertEquals('1', $result->status);

        $request = ['role' => 'admin', 'status' => '0'];
        $result = $this->repository->createOrUpdate($user->id, $request);
        $this->assertEquals('0', $result->status);
    }

    public function testCreateOrUpdateHandleErrors()
    {
        $request = [
            'role' => null,
        ];

        $result = $this->repository->createOrUpdate(null, $request);
        $this->assertFalse($result);
    }
}
