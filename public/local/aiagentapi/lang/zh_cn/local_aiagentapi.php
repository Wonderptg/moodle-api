<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Simplified Chinese language strings for local_aiagentapi.
 *
 * @package     local_aiagentapi
 * @copyright   2026
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'AI agent API';

$string['aiagentapi:use'] = '使用 AI agent API';
$string['aiagentapi:calendarwrite'] = '通过 AI agent API 写入日历';

$string['deviceverify_title'] = '小林课堂授权登录';
$string['deviceverify_heading'] = '小林课堂授权登录';
$string['deviceverify_intro'] = '小林课堂正在请求访问此 Moodle 账号。';
$string['deviceverify_invalidcode'] = '此登录验证码无效。';
$string['deviceverify_expiredcode'] = '此登录验证码已过期，请重新发起小林课堂登录。';
$string['deviceverify_servicenotavailable'] = '请求的课堂服务不可用：{$a}';
$string['deviceverify_notallowed'] = '当前账号暂未开通小林课堂访问权限。';
$string['deviceverify_approved_heading'] = '授权已完成';
$string['deviceverify_approved_message'] = '授权已完成，可以返回小林课堂。';
$string['deviceverify_denied_heading'] = '已拒绝授权';
$string['deviceverify_denied_message'] = '已拒绝本次登录请求，可以关闭此页面。';
$string['deviceverify_alreadyapproved'] = '本次授权已完成，可以返回小林课堂。';
$string['deviceverify_alreadydenied'] = '本次登录请求已被拒绝。';
$string['deviceverify_detail_user'] = '用户：{$a->user}';
$string['deviceverify_detail_service'] = '应用：小林课堂';
$string['deviceverify_detail_code'] = '验证码：{$a->code}';
$string['deviceverify_detail_expiresat'] = '过期时间：{$a->expiresat}';
$string['deviceverify_approve_button'] = '授权登录';
$string['deviceverify_deny_button'] = '拒绝';

$string['accessmanage_title'] = '小林课堂授权用户';
$string['accessmanage_intro'] = '只给允许使用小林课堂 AI 登录的学生开通。这里会自动授予 Moodle 权限、加入 local_aiagentapi 服务允许列表，并准备服务专用 token；页面不会显示 token。';
$string['accessmanage_identifier'] = '学生账号';
$string['accessmanage_identifier_placeholder'] = '手机号、用户名或邮箱';
$string['accessmanage_identifier_help'] = '优先填写 Moodle 用户名。手机号会按用户资料里的手机号字段查找。';
$string['accessmanage_grantbutton'] = '开通小林课堂';
$string['accessmanage_revokebutton'] = '关闭';
$string['accessmanage_empty'] = '当前还没有学生开通小林课堂。';
$string['accessmanage_granted'] = '已为 {$a->fullname}（{$a->username}）开通小林课堂。';
$string['accessmanage_revoked'] = '已为 {$a} 关闭小林课堂。';
$string['accessmanage_usernotfound'] = '没有找到这个 Moodle 用户。';
$string['accessmanage_userdeleted'] = '这个用户已经被删除。';
$string['accessmanage_usersuspended'] = '这个用户已被停用。';
$string['accessmanage_userunconfirmed'] = '这个用户还没有确认账号。';
$string['accessmanage_table_user'] = '用户';
$string['accessmanage_table_username'] = '用户名';
$string['accessmanage_table_phone'] = '手机号';
$string['accessmanage_table_status'] = '状态';
$string['accessmanage_table_token'] = 'Token';
$string['accessmanage_table_action'] = '操作';
$string['accessmanage_status_confirmed'] = '已确认';
$string['accessmanage_status_unconfirmed'] = '未确认';
$string['accessmanage_status_suspended'] = '已停用';
$string['accessmanage_token_ready'] = '已准备';
$string['accessmanage_token_missing'] = '缺失';
$string['accessrole_name'] = '小林课堂用户';
$string['accessrole_description'] = '允许指定用户通过 local_aiagentapi Moodle 服务登录小林课堂。';
$string['accessmanage_tokenname'] = '小林课堂 token';
