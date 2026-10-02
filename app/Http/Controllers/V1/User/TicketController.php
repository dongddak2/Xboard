<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->where('user_id', $request->user()->id)
                ->first()
                ->load('message');
            if (!$ticket) {
                return $this->fail([400, __('Ticket does not exist')]);
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            $ticket['message']->each(function ($message) use ($ticket) {
                $message['is_me'] = ($message['user_id'] == $ticket->user_id);
            });
            return $this->success(TicketResource::make($ticket)->additional(['message' => true]));
        }
        $ticket = Ticket::where('user_id', $request->user()->id)
            ->orderBy('created_at', 'DESC')
            ->get();
        return $this->success(TicketResource::collection($ticket));
    }

    public function save(TicketSave $request)
    {
        try{
            DB::beginTransaction();
            if ((int)Ticket::where('status', 0)->where('user_id', $request->user()->id)->lockForUpdate()->count()) {
                throw new \Exception(__('There are other unresolved tickets'));
            }
            $ticket = Ticket::create(array_merge($request->only([
                'subject',
                'level'
            ]), [
                'user_id' => $request->user()->id
            ]));
            if (!$ticket) {
                throw new \Exception(__('There are other unresolved tickets'));
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $request->user()->id,
                'ticket_id' => $ticket->id,
                'message' => $request->input('message')
            ]);
            if (!$ticketMessage) {
                throw new \Exception(__('Failed to open ticket'));
            }
            DB::commit();
            $this->sendNotify($ticket, $request->input('message'), $request->user()->id);
            return $this->success(true);
        }catch(\Exception $e){
            DB::rollBack();
            \Log::error($e);
            return $this->fail([400, $e->getMessage()]);
        }
        
    }

    public function reply(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([400, __('Invalid parameter')]);
        }
        if (empty($request->input('message'))) {
            return $this->fail([400, __('Message cannot be empty')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ($ticket->status) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }
        if ($request->user()->id == $this->getLastMessage($ticket->id)->user_id) {
            return $this->fail([400, __('Please wait for the technical enginneer to reply')]);
        }
        $ticketService = new TicketService();
        if (!$ticketService->reply(
            $ticket,
            $request->input('message'),
            $request->user()->id
        )) {
            return $this->fail([400, __('Ticket reply failed')]);
        }
        $this->sendNotify($ticket, $request->input('message'), $request->user()->id);
        return $this->success(true);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            return $this->fail([422, __('Invalid parameter')]);
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user()->id)
            ->first();
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }
        return $this->success(true);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int)admin_setting('withdraw_close_enable', 0)) {
            return $this->fail([400, __('Unsupported withdrawal method')]);
        }
        if (!in_array(
            $request->input('withdraw_method'),
            admin_setting('commission_withdraw_method',Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT)
        )) {
            return $this->fail([422, __('Unsupported withdrawal method')]);
        }
        $user = User::find($request->user()->id);
        $limit = admin_setting('commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            return $this->fail([422, __('The current required minimum withdrawal commission is :limit', ['limit' => $limit])]);
        }
        try{
            DB::beginTransaction();
            $subject = __('[Commission Withdrawal Request] This ticket is opened by the system');
            $ticket = Ticket::create([
                'subject' => $subject,
                'level' => 2,
                'user_id' => $request->user()->id
            ]);
            if (!$ticket) {
                return $this->fail([400, __('Failed to open ticket')]);
            }
            $message = sprintf("%s\r\n%s",
                __('Withdrawal method') . "：" . $request->input('withdraw_method'),
                __('Withdrawal account') . "：" . $request->input('withdraw_account')
            );
            $ticketMessage = TicketMessage::create([
                'user_id' => $request->user()->id,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                DB::rollBack();
                return $this->fail([400, __('Failed to open ticket')]);
            }
            DB::commit();
        }catch(\Exception $e){
            DB::rollBack();
            throw $e;
        }
        $this->sendNotify($ticket, $message, $request->user()->id);
        return $this->success(true);
    }

    private function sendNotify(Ticket $ticket, string $message, $user_id)
    {
        $user = User::find($user_id)->load('plan');
        $transfer_enable = $this->getFlowData($user->transfer_enable); // 总流量
        $remaining_traffic = $this->getFlowData($user->transfer_enable - $user->u - $user->d); // 剩余流量
        $u = $this->getFlowData($user->u); // 上传
        $d = $this->getFlowData($user->d); // 下载
        $expired_at = date("Y-m-d h:m:s", $user->expired_at); // 到期时间
        $money = $user->balance / 100;
        $affmoney = $user->commission_balance / 100;
        $plan = $user->plan;
        $ip = request()->ip();
        $region = filter_var($ip,FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (new \Ip2Region())->simple($ip) : "NULL";
        $TGmessage = "📮工单提醒 #{$ticket->id}\n———————————————\n";
        $TGmessage .= "邮箱: `{$user->email}`\n";
        $TGmessage .= "用户位置: \n`{$region}`\n";
        if($user->plan){
            $TGmessage .= "套餐与流量: \n`{$plan->name} {$transfer_enable}/{$remaining_traffic}`\n";
            $TGmessage .= "上传/下载: \n`{$u}/{$d}`\n";
            $TGmessage .= "到期时间: \n`{$expired_at}`\n";
        }else{
            $TGmessage .= "套餐与流量: \n`未订购任何套餐`\n";
        }
        $TGmessage .= "余额/佣金余额: \n`{$money}/{$affmoney}`\n";
        $TGmessage .= "主题：\n`{$ticket->subject}`\n内容：\n`{$message}`\n";
        $telegramService = new TelegramService();
        $telegramService->sendMessageWithAdmin($TGmessage, true);
    }

    private function getFlowData($b)
    {
        $m = $b / (1024 * 1024);
        if ($m >= 1024) {
            $g = $m / 1024;
            $text = round($g, 2) . "GB";
        } else {
            $text = round($m, 2) . "MB";
        }
        return $text;
    }
}
