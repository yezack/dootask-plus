<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

class UpdateProjectTasksSubtaskVisibility extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $prefix = DB::getTablePrefix();
        $now = Carbon::now();
        DB::statement("
            UPDATE {$prefix}project_tasks AS subtask
            INNER JOIN {$prefix}project_tasks AS parent ON subtask.parent_id = parent.id
            SET
                subtask.visibility = parent.visibility,
                subtask.updated_at = '{$now}'
            WHERE subtask.parent_id > 0
        ");
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // No need for down operation as this is a data correction
    }
}
