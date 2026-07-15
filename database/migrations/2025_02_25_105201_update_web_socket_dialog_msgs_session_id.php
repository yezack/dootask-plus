<?php

use App\Models\WebSocketDialog;
use App\Models\WebSocketDialogMsg;
use App\Models\WebSocketDialogSession;
use App\Module\Base;
use Illuminate\Database\Migrations\Migration;

class UpdateWebSocketDialogMsgsSessionId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $list = WebSocketDialog::select(['web_socket_dialogs.*', 'u.email'])
            ->join('web_socket_dialog_users as du', 'web_socket_dialogs.id', '=', 'du.dialog_id')
            ->join('users as u', 'du.userid', '=', 'u.userid')
            ->where('u.email', 'like', 'ai-%@bot.system')
            ->where('web_socket_dialogs.type', 'user')
            ->get();
        foreach ($list as $item) {
            $msg = WebSocketDialogMsg::whereDialogId($item->id)->whereSessionId(0)->orderBy('id')->first();
            if ($msg || empty($item->session_id)) {
                $title = $msg?->key;
                $session = WebSocketDialogSession::createInstance([
                    'dialog_id' => $item->id,
                    'title' => $title ? Base::cutStr($title, 100) : 'Unknown',
                    'created_at' => $item->created_at,
                ]);
                $session->save();
                if (empty($item->session_id)) {
                    $item->session_id = $session->id;
                    $item->save();
                }
                if ($msg) {
                    WebSocketDialogMsg::whereDialogId($item->id)->whereSessionId(0)->update(['session_id' => $session->id]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        //
    }
}
