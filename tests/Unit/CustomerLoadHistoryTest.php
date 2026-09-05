<?php

namespace Tests\Unit;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use PHPUnit\Framework\TestCase;

class CustomerLoadHistoryTest extends TestCase
{
    public function test_history_is_scoped_deduplicated_and_excludes_self(): void
    {
        self::assertSame('sqlite', getenv('DB_CONNECTION'));
        self::assertSame(':memory:', getenv('DB_DATABASE'));
        $db = new Manager;
        $db->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        $db->bootEloquent();
        $connection = $db->getConnection();
        self::assertSame('sqlite', $connection->getConfig('driver'));
        self::assertSame(':memory:', $connection->getConfig('database'));
        self::assertNull($connection->getConfig('host'));
        self::assertNull($connection->getConfig('port'));
        $schema = $connection->getSchemaBuilder();
        $schema->create('customers', function (Blueprint $table): void {
            $table->id();
            $table->integer('user_id')->nullable();
        });
        $schema->create('loads', function (Blueprint $table): void {
            $table->id();
            $table->integer('customer_user_id');
            $table->integer('consignee_customer_id')->nullable();
            $table->integer('company_id')->nullable();
            $table->integer('assigned_driver_user_id')->nullable();
        });
        $schema->create('company_user', function (Blueprint $table): void {
            $table->integer('company_id');
            $table->integer('user_id');
            $table->string('status');
        });
        $connection->table('customers')->insert([
            ['id' => 1, 'user_id' => 1], ['id' => 2, 'user_id' => 2],
            ['id' => 3, 'user_id' => null], ['id' => 4, 'user_id' => 4],
        ]);
        $connection->table('company_user')->insert([
            ['company_id' => 10, 'user_id' => 7, 'status' => 'active'],
            ['company_id' => 10, 'user_id' => 8, 'status' => 'pending'],
        ]);
        $connection->table('loads')->insert([
            ['customer_user_id' => 1, 'consignee_customer_id' => 3, 'company_id' => 10, 'assigned_driver_user_id' => 9],
            ['customer_user_id' => 1, 'consignee_customer_id' => 3, 'company_id' => 10, 'assigned_driver_user_id' => 9],
            ['customer_user_id' => 2, 'consignee_customer_id' => 4, 'company_id' => 20, 'assigned_driver_user_id' => null],
        ]);
        foreach ([1 => [3], 7 => [1, 3], 9 => [1, 3], 8 => [], 99 => [], 2 => [4]] as $id => $expected) {
            $user = new User;
            $user->id = $id;
            self::assertSame($expected, Customer::query()->fromLoadHistory($user)->orderBy('id')->pluck('id')->all());
        }
        $connection->disconnect();
    }
}
