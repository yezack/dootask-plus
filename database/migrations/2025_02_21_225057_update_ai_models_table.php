<?php

use App\Models\Setting;
use App\Module\Base;
use Illuminate\Database\Migrations\Migration;

class UpdateAiModelsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $row = Setting::whereName('aibotSetting')->first();
        if (empty($row)) {
            return;
        }
        $value = Base::json2array($row->getRawOriginal('setting'));
        foreach ($value as $key => $item) {
            if (str_ends_with($key, '_models')) {
                $value[$key] = preg_replace('/\s*:\s*/', ' | ', $item);
            }
        }
        $row->setting = Base::array2json($value);
        $row->save();
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $row = Setting::whereName('aibotSetting')->first();
        if (empty($row)) {
            return;
        }
        $value = Base::json2array($row->getRawOriginal('setting'));
        foreach ($value as $key => $item) {
            if (str_ends_with($key, '_models')) {
                $value[$key] = preg_replace('/\s*\|\s*/', ': ', $item);
            }
        }
        $row->setting = Base::array2json($value);
        $row->save();
    }
}
