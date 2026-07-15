<?php

namespace App\Models;

use App\Exceptions\ApiException;
use App\Module\Base;

/**
 * App\Models\ReportLink
 *
 * @property int $id
 * @property int|null $rid 报告ID
 * @property int|null $num 累计访问
 * @property string|null $code 链接码
 * @property int|null $userid 会员ID
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Report|null $report
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel cancelAppend()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel cancelHidden()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel change($array)
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel getKeyValue()
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink query()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel remove()
 * @method static \Illuminate\Database\Eloquent\Builder|AbstractModel saveOrIgnore()
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereNum($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereRid($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder|ReportLink whereUserid($value)
 * @mixin \Eloquent
 */
class ReportLink extends AbstractModel
{
    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function report(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Report::class, 'id', 'report_id');
    }

    /**
     * 生成链接
     * @param $rid
     * @param $userid
     * @param $refresh
     * @return array
     */
    public static function generateLink($rid, $userid, $refresh = false)
    {
        $report = Report::find($rid);
        if (empty($report)) {
            throw new ApiException('报告不存在或已被删除');
        }
        if ($report->userid != $userid) {
            if (!ReportReceive::whereRid($rid)->whereUserid($userid)->exists()) {
                throw new ApiException('您没有权限查看该报告');
            }
        }
        $reportLink = ReportLink::whereRid($rid)->whereUserid($userid)->first();
        if (empty($reportLink)) {
            $reportLink = ReportLink::createInstance([
                'rid' => $rid,
                'userid' => $userid,
                'code' => base64_encode("{$rid},{$userid}," . Base::generatePassword()),
            ]);
            $reportLink->save();
        } else {
            if ($refresh == 'yes') {
                $reportLink->code = base64_encode("{$rid},{$userid}," . Base::generatePassword());
                $reportLink->save();
            }
        }
        return [
            'id' => $rid,
            'url' => Base::fillUrl('single/report/detail/' . $reportLink->code),
            'code' => $reportLink->code,
            'num' => $reportLink->num
        ];
    }
}
