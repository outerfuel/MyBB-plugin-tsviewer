<?php
/**
 * TeamSpeak 6 Viewer
 * Optimized for MyBB 1.8.x and PHP 8.2+
 */

if(!defined("IN_MYBB")) {
    die("Direct initialization of this file is not allowed.");
}

$plugins->add_hook("global_start", "tsviewer_run");
$plugins->add_hook("admin_page_output_footer", "tsviewer_admin_preview");

function tsviewer_info() {
    return array(
        "name"          => "TeamSpeak Viewer",
        "description"   => "Built on TeamSpeak 6. Displays users, idle times, away status, channels, and more. Supports HTTP or HTTPS WebQuery. Admin settings comes with a style editor.",
        "author"        => "DEFAULT",
        "version"       => "1.1",
        "compatibility" => "18*"
    );
}

function tsviewer_activate() {
    global $db;

    $group_exists = $db->simple_select("settinggroups", "gid", "name='tsviewer_group'");
    if($db->num_rows($group_exists) > 0) {
        $gid = (int)$db->fetch_field($group_exists, "gid");
    } else {
        $gid = $db->insert_query("settinggroups", array(
            'name'        => 'tsviewer_group',
            'title'       => 'TeamSpeak Viewer Settings',
            'description' => 'Settings for the TeamSpeak 6 Viewer.',
            'disporder'   => 5,
            'isdefault'   => 0
        ));
    }

    if(empty($gid)) {
        return;
    }

    $settings = array(
        array(
            'name'        => 'tsviewer_url',
            'title'       => 'API Base URL',
            'description' => 'Your TeamSpeak API Base URL with Server ID (e.g., http://127.0.0.1:10080/1)',
            'optionscode' => 'text',
            'value'       => 'http://127.0.0.1:10080/1'
        ),
        array(
            'name'        => 'tsviewer_apikey',
            'title'       => 'API Key',
            'description' => 'Your TeamSpeak 6 Web API Key. (recommended to use api key scope=read for safety)',
            'optionscode' => 'text',
            'value'       => ''
        ),
        array(
            'name'        => 'tsviewer_channel_html',
            'title'       => 'Channel Header HTML',
            'description' => 'Available variables: {channel_name}',
            'optionscode' => 'textarea',
            'value'       => '<div style="font-family: \'Segoe UI\', system-ui, sans-serif; font-size: 10px; font-weight: 700; color: inherit; text-transform: uppercase; letter-spacing: 1px; padding: 2px 0; border-bottom: 1px solid currentColor; display: flex; align-items: center; opacity: 0.9;"><span style="background: currentColor; width: 2px; height: 8px; margin-right: 5px; opacity: 0.5;"></span>{channel_name}</div>'
        ),
        array(
            'name'        => 'tsviewer_user_html',
            'title'       => 'User Row HTML',
            'description' => 'Available variables: {username}, {away_status}, {idle_color}, {idle_time}',
            'optionscode' => 'textarea',
            'value'       => '<div style="padding-left: 10px; margin-bottom: 1px; font-family: \'Segoe UI\', sans-serif; font-size: 11px; color: inherit; display: flex; align-items: center;"><span style="color: inherit; margin-right: 5px; font-size: 9px; opacity: 0.4;">└</span><span style="font-weight: 500;">{username}</span><span style="font-family: monospace; font-size: 9px; color: {idle_color}; opacity: 0.6; margin-left: 5px;">[{idle_time}]</span>{away_status}</div>'
        ),
        array(
            'name'        => 'tsviewer_status_online',
            'title'       => 'Online Status HTML',
            'description' => 'Available variables: {user_count} | Available variables with a manage api key: {extra_stats}, {version}, {platform}',
            'optionscode' => 'textarea',
            'value'       => '<div style="padding: 4px 0; border-top: 1px solid rgba(0,0,0,0.05); font-family: \'Segoe UI\', sans-serif; font-size: 10px; color: inherit; opacity: 0.8;"><span style="color: inherit; font-weight: bold;">[LIVE]</span> {user_count} Users {extra_stats}</div>'
        ),
        array(
            'name'        => 'tsviewer_status_offline',
            'title'       => 'Offline Status HTML',
            'description' => 'Available variables: {last_seen}',
            'optionscode' => 'textarea',
            'value'       => '<div style="padding: 4px 0; border-top: 1px solid rgba(0,0,0,0.05); font-family: \'Segoe UI\', sans-serif; font-size: 10px; color: #e74c3c;"><span style="font-weight: bold;">[OFFLINE]</span> Lost: {last_seen}</div>'
        )
    );

    foreach($settings as $i => $setting) {
        $query = $db->simple_select("settings", "name", "name='".$db->escape_string($setting['name'])."'");
        if($db->num_rows($query) == 0) {
            $db->insert_query('settings', array(
                'name'        => $db->escape_string($setting['name']),
                'title'       => $db->escape_string($setting['title']),
                'description' => $db->escape_string($setting['description']),
                'optionscode' => $db->escape_string($setting['optionscode']),
                'value'       => $db->escape_string($setting['value']),
                'disporder'   => $i + 1,
                'gid'         => $gid
            ));
        }
    }

    rebuild_settings();
}

function ts_fetch_api($url, $api_key) {
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array("x-api-key: $api_key", "Connection: close"),
        CURLOPT_TIMEOUT        => 2,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => true,
    ));
    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($response && $http_code === 200) ? json_decode($response, true) : null;
}

function tsviewer_encrypt($data, $key) {
    $iv        = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . base64_encode($iv));
}

function tsviewer_decrypt($data, $key) {
    $data = base64_decode($data);
    if(strpos($data, '::') === false) return null;
    list($encrypted_data, $iv_b64) = explode('::', $data, 2);
    $iv = base64_decode($iv_b64);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

function tsviewer_load_cache($cache_file, $api_key) {
    if(!file_exists($cache_file)) return null;
    $raw       = file_get_contents($cache_file);
    $decrypted = tsviewer_decrypt($raw, $api_key);
    if(!$decrypted) return null;
    return json_decode($decrypted, true) ?: null;
}

function tsviewer_format_idle($idle_sec) {
    $h = floor($idle_sec / 3600);
    $m = floor(($idle_sec % 3600) / 60);
    $s = $idle_sec % 60;

    if($h > 0) {
        return $m > 0 ? "{$h}h {$m}m" : "{$h}h";
    }
    if($m > 0) {
        return "{$m}m";
    }
    return "{$s}s";
}

function tsviewer_time_ago($timestamp) {
    $diff = time() - $timestamp;
    if($diff >= 3600) return floor($diff / 3600) . " hr ago";
    if($diff >= 60)   return floor($diff / 60) . " min ago";
    return "just now";
}

function tsviewer_run() {
    global $mybb, $ts_online_users, $ts_count, $ts_status;

    $ts_count        = 0;
    $ts_online_users = "";
    $ts_status       = "";

    $base_url_setting = $mybb->settings['tsviewer_url'];
    $api_key          = $mybb->settings['tsviewer_apikey'];
    $tpl_channel      = $mybb->settings['tsviewer_channel_html'];
    $tpl_user         = $mybb->settings['tsviewer_user_html'];
    $tpl_online       = $mybb->settings['tsviewer_status_online'];
    $tpl_offline      = $mybb->settings['tsviewer_status_offline'];

    if(empty($api_key) || empty($base_url_setting)) {
        $ts_status = "API Key or URL missing.";
        return;
    }

    $cache_file = MYBB_ROOT . "cache/ts_cache.dat";
    $cache_time = 60;

    if(file_exists($cache_file) && (time() - filemtime($cache_file) < $cache_time)) {
        $cache_data = tsviewer_load_cache($cache_file, $api_key);
        if($cache_data) {
            $ts_count        = (int)$cache_data['count'];
            $ts_online_users = $cache_data['users'];
            $ts_status       = $cache_data['status'];
            return;
        }
    }

    $base_api      = rtrim($base_url_setting, '/');
    $clients_data  = ts_fetch_api($base_api . '/clientlist?-times&-away', $api_key);
    $channels_data = ts_fetch_api($base_api . '/channellist', $api_key);

    $needs_server_info = (
        strpos($tpl_online, '{extra_stats}') !== false ||
        strpos($tpl_online, '{version}')     !== false ||
        strpos($tpl_online, '{platform}')    !== false
    );
    $server_data = $needs_server_info ? ts_fetch_api($base_api . '/serverinfo', $api_key) : null;

    if($clients_data) {
        $clients_by_channel = array();
        if(isset($clients_data['body'])) {
            foreach($clients_data['body'] as $client) {
                if(isset($client['client_type']) && $client['client_type'] == 0 && !empty($client['client_nickname'])) {
                    $cid      = $client['cid'];
                    $name     = htmlspecialchars($client['client_nickname']);
                    $idle_sec = isset($client['client_idle_time']) ? (int)floor($client['client_idle_time'] / 1000) : 0;
                    $idle_str = tsviewer_format_idle($idle_sec);
                    $hue      = max(0, 120 - ($idle_sec / 60));
                    $idle_color = "hsl({$hue}, 60%, 50%)";

                    $away_str = "";
                    if(isset($client['client_away']) && $client['client_away'] == 1) {
                        $msg      = !empty($client['client_away_message']) ? htmlspecialchars($client['client_away_message']) : "";
                        $away_str = " <span style='color: #e67e22; font-weight: bold;'>[Away" . ($msg ? ": {$msg}" : "") . "]</span>";
                    }

                    $clients_by_channel[$cid][] = str_replace(
                        array('{username}', '{away_status}', '{idle_color}', '{idle_time}'),
                        array($name, $away_str, $idle_color, $idle_str),
                        $tpl_user
                    );
                    $ts_count++;
                }
            }
        }

        $html_output = "";
        if(empty($clients_by_channel)) {
            $html_output = "<em>No users online</em>";
        } elseif(isset($channels_data['body'])) {
            foreach($channels_data['body'] as $ch) {
                $cid = $ch['cid'];
                if(isset($clients_by_channel[$cid])) {
                    $cname     = htmlspecialchars($ch['channel_name']);
                    $indent    = (isset($ch['pid']) && $ch['pid'] > 0) ? 12 : 0;
                    $ch_header = str_replace('{channel_name}', $cname, $tpl_channel);
                    $html_output .= "<div style='margin-bottom: 6px; padding-left: {$indent}px;'>{$ch_header}<div>" . implode("", $clients_by_channel[$cid]) . "</div></div>";
                }
            }
        }

        $extra_stats = "";
        $version     = "Unknown";
        $platform    = "Unknown";
        if($server_data && isset($server_data['body'][0])) {
            $s_info   = $server_data['body'][0];
            $version  = $s_info['virtualserver_version']  ?? "Unknown";
            $platform = $s_info['virtualserver_platform'] ?? "Unknown";
            if(isset($s_info['virtualserver_uptime'])) {
                $u_sec       = (int)$s_info['virtualserver_uptime'];
                $extra_stats = " | Uptime: " . floor($u_sec / 86400) . "d " . floor(($u_sec % 86400) / 3600) . "h";
            }
        }

        $ts_status = str_replace(
            array('{user_count}', '{extra_stats}', '{version}', '{platform}'),
            array($ts_count, $extra_stats, $version, $platform),
            $tpl_online
        );
        $ts_online_users = $html_output;

        $payload = json_encode(array(
            'count'     => $ts_count,
            'users'     => $ts_online_users,
            'status'    => $ts_status,
            'last_seen' => time()
        ));
        @file_put_contents($cache_file, tsviewer_encrypt($payload, $api_key));

    } else {
        $cache_data = tsviewer_load_cache($cache_file, $api_key);
        if($cache_data) {
            $ts_count        = (int)$cache_data['count'];
            $ts_online_users = $cache_data['users'];
            $last_seen       = isset($cache_data['last_seen']) ? $cache_data['last_seen'] : filemtime($cache_file);
            $ts_status       = str_replace('{last_seen}', tsviewer_time_ago($last_seen), $tpl_offline);
        } else {
            $ts_status = str_replace('{last_seen}', 'unknown', $tpl_offline);
        }
    }
}

function tsviewer_deactivate() {
    global $db;
    $db->delete_query("settings", "name LIKE 'tsviewer_%'");
    $db->delete_query("settinggroups", "name = 'tsviewer_group'");
    rebuild_settings();
    $cache_file = MYBB_ROOT . "cache/ts_cache.dat";
    if(file_exists($cache_file)) {
        @unlink($cache_file);
    }
}

function tsviewer_admin_preview() {
    global $mybb, $db;

    if(
        isset($mybb->input['module']) && $mybb->input['module'] == 'config-settings' &&
        isset($mybb->input['action']) && $mybb->input['action'] == 'change'
    ) {
        $gid   = (int)$mybb->input['gid'];
        $query = $db->simple_select("settinggroups", "name", "gid='{$gid}'");

        if($db->fetch_field($query, "name") == 'tsviewer_group') {
            echo '<style>
                .ts_setting_group { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; margin-bottom: 25px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
                .ts_setting_group h4 { margin: 0; background: #f8f9fa; color: #444; padding: 12px 20px; font-size: 11px; text-transform: uppercase; letter-spacing: 1px; border-bottom: 1px solid #eee; font-weight: 700; }
                .tsviewer_row_highlight { border-left: 3px solid #ddd !important; transition: all 0.2s ease; }
                .tsviewer_row_highlight:focus-within { border-left-color: #2ecc71 !important; background: #fafafa !important; }
                .state-label { font-size: 10px; font-weight: bold; text-transform: uppercase; margin-bottom: 10px; display: block; letter-spacing: 0.5px; }
            </style>

            <div id="tsviewer_preview_container" style="background:#f8f9fa; padding:25px; margin: 20px 0; border: 1px solid #ccc; border-radius: 8px; font-family: \'Inter\', sans-serif;">
                <h3 style="color:#333; margin-top:0; border-bottom:1px solid #ddd; padding-bottom:15px; font-size: 15px; font-weight: 600;">Global Component Visualizer</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; margin-top: 15px;">
                    <div id="section_tree" style="background: #fff; padding: 20px; border-radius: 6px; border: 1px solid #ddd; transition: border-color 0.3s; color: #333;">
                        <span class="state-label" style="color: #009977;">Online Mode: User Tree</span>
                        <div id="tsviewer_tree_preview"></div>
                    </div>
                    <div style="display: flex; flex-direction: column; gap: 20px;">
                        <div id="section_online" style="background: #fff; padding: 20px; border-radius: 6px; border: 1px solid #ddd; transition: border-color 0.3s; color: #333;">
                            <span class="state-label" style="color: #2ecc71;">Global Status: Online State</span>
                            <div id="tsviewer_online_preview"></div>
                        </div>
                        <div id="section_offline" style="background: #fff; padding: 20px; border-radius: 6px; border: 1px solid #ddd; transition: border-color 0.3s; color: #333;">
                            <span class="state-label" style="color: #e74c3c;">Global Status: Offline State</span>
                            <div id="tsviewer_offline_preview"></div>
                        </div>
                    </div>
                </div>
            </div>

            <script type="text/javascript">
                function updateTSPreview() {
                    const chHtml     = $("[name*=\'tsviewer_channel_html\']").val() || "";
                    const usrHtml    = $("[name*=\'tsviewer_user_html\']").val() || "";
                    const onlineHtml = $("[name*=\'tsviewer_status_online\']").val() || "";
                    const offlineHtml = $("[name*=\'tsviewer_status_offline\']").val() || "";

                    const treePreview = "<div style=\'margin-bottom:8px;\'>"+chHtml.replace("{channel_name}", "Lobby")+"</div><div>"+usrHtml.replace("{username}", "User1").replace("{away_status}", "").replace("{idle_color}", "#2ecc71").replace("{idle_time}", "2m")+"</div>";
                    const onlinePreview = onlineHtml.replace("{user_count}", "5").replace("{extra_stats}", " | Uptime: 1d 4h").replace("{version}", "3.x.x").replace("{platform}", "Linux");

                    $("#tsviewer_tree_preview").html(treePreview);
                    $("#tsviewer_online_preview").html(onlinePreview);
                    $("#tsviewer_offline_preview").html(offlineHtml.replace("{last_seen}", "just now"));
                }
                $(document).ready(function() {
                    $(".form_container").before($("#tsviewer_preview_container"));
                    const $form = $(".form_container");
                    $form.prepend(\'<div id="ts_group_status" class="ts_setting_group"><h4>Status Message Design</h4></div><div id="ts_group_tree" class="ts_setting_group"><h4>User Tree Design</h4></div><div id="ts_group_core" class="ts_setting_group"><h4>Connection Settings</h4></div>\');
                    $("[name*=\'tsviewer_url\'], [name*=\'tsviewer_apikey\']").closest("tr").appendTo("#ts_group_core").addClass("tsviewer_row_highlight");
                    $("[name*=\'tsviewer_channel_html\'], [name*=\'tsviewer_user_html\']").closest("tr").appendTo("#ts_group_tree").addClass("tsviewer_row_highlight");
                    $("[name*=\'tsviewer_status_online\'], [name*=\'tsviewer_status_offline\']").closest("tr").appendTo("#ts_group_status").addClass("tsviewer_row_highlight");

                    $("[name*=\'tsviewer_channel_html\'], [name*=\'tsviewer_user_html\']").focus(function(){ $("#section_tree").css("border-color", "#2ecc71"); }).blur(function(){ $("#section_tree").css("border-color", "#ddd"); });
                    $("[name*=\'tsviewer_status_online\']").focus(function(){ $("#section_online").css("border-color", "#2ecc71"); }).blur(function(){ $("#section_online").css("border-color", "#ddd"); });
                    $("[name*=\'tsviewer_status_offline\']").focus(function(){ $("#section_offline").css("border-color", "#e74c3c"); }).blur(function(){ $("#section_offline").css("border-color", "#ddd"); });

                    updateTSPreview();
                    $("[name*=\'tsviewer_\']").on("keyup change", updateTSPreview);
                });
            </script>';
        }
    }
}