<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class CouriersTableSeeder extends Seeder
{

    /**
     * Auto generated seed file
     *
     * @return void
     */
    public function run()
    {
        

        \DB::table('couriers')->delete();
        
        \DB::table('couriers')->insert(array (
            0 => 
            array (
                'id' => 1,
                'code' => 'jne',
                'name' => 'JNE',
                'company_name' => NULL,
                'created_at' => '2023-01-14 16:49:46',
                'updated_at' => '2023-01-14 16:49:46',
            ),
            1 => 
            array (
                'id' => 2,
                'code' => 'anteraja',
                'name' => 'AnterAja',
                'company_name' => NULL,
                'created_at' => '2023-01-14 16:49:46',
                'updated_at' => '2023-01-14 17:12:27',
            ),
            2 => 
            array (
                'id' => 3,
                'code' => 'jnt',
                'name' => 'J&T',
                'company_name' => NULL,
                'created_at' => '2023-01-14 16:49:46',
                'updated_at' => '2023-01-14 17:12:27',
            ),
            3 => 
            array (
                'id' => 4,
                'code' => 'sicepat',
                'name' => 'Sicepat Express',
                'company_name' => NULL,
                'created_at' => '2023-01-14 16:49:46',
                'updated_at' => '2023-01-14 16:49:46',
            ),
            4 => 
            array (
                'id' => 5,
                'code' => 'ninja',
                'name' => 'Ninja Express',
                'company_name' => NULL,
                'created_at' => '2023-01-14 16:49:46',
                'updated_at' => '2023-01-14 16:49:46',
            ),
            5 => 
            array (
                'id' => 6,
                'code' => 'pos',
                'name' => 'POS Indonesia',
                'company_name' => NULL,
                'created_at' => '2023-01-14 16:49:54',
                'updated_at' => '2023-01-14 16:50:09',
            ),
            6 => 
            array (
                'id' => 7,
                'code' => 'tiki',
            'name' => 'Tiki (Titipan Kilat)',
                'company_name' => NULL,
                'created_at' => '2023-01-14 20:02:00',
                'updated_at' => '2023-01-14 20:02:06',
            ),
        ));
        
        
    }
}