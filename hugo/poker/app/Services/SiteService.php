<?php

namespace App\Services;

class SiteService
{
    const RENEW_COLUMNS = [
        'ctx_id', 'git_name', 'git_account', 'domain', 'site_title', 'site_subtitle',
        'site_logo', 'languages', 'sns_id', 'topnav_menus', 'keyword', 'theme_name',
        'theme_type', 'sitedir', 'deploy', 'hostip', 'local_deploy', 'local_hostip',
        'status', 'json', 'time',
    ];

    const EXPORT_COLUMNS = [
        'ctx_id', 'git_name', 'git_account', 'status', 'theme_type', 'languages',
        'domain', 'sns_id', 'topnav_menus', 'site_title', 'site_subtitle', 'json',
    ];

    public static function sanitizePost(array $post)
    {
        array_walk($post, function (&$item) {
            $item = str_replace('|', '', $item);
        });
        return $post;
    }

    public static function defaultForm()
    {
        return [
            'post_gitname' => '',
            'post_gitaccount' => '',
            'post_domain' => '',
            'post_sitetitle' => '',
            'post_sitelogo' => '',
            'post_lang' => '',
            'post_sns_id' => '',
            'post_topnavmenus' => '',
            'post_keyword' => '',
            'post_themetype' => 'poker',
            'post_sitetype' => 'cta',
            'post_sitedir' => '',
            'post_sitedeploy' => 'cloudflare',
            'post_sitehostip' => '',
            'local_deploy' => '',
            'local_hostip' => '',
            'post_description' => '',
            'post_status' => '',
            'post_uuid' => uuid(),
        ];
    }

    public static function formFromRow(array $row)
    {
        $extraJson = json_decode(isset($row['json']) ? $row['json'] : '', true);
        if (!is_array($extraJson)) {
            $extraJson = [];
        }
        $pick = function (array $row, $key) {
            return isset($row[$key]) ? trim($row[$key]) : '';
        };
        return [
            'post_gitname' => $pick($row, 'git_name'),
            'post_gitaccount' => $pick($row, 'git_account'),
            'post_domain' => $pick($row, 'domain'),
            'post_sitetitle' => $pick($row, 'site_title'),
            'post_sitelogo' => $pick($row, 'site_logo'),
            'post_lang' => $pick($row, 'languages'),
            'post_sns_id' => $pick($row, 'sns_id'),
            'post_topnavmenus' => $pick($row, 'topnav_menus'),
            'post_keyword' => $pick($row, 'keyword'),
            'post_themetype' => $pick($row, 'theme_type'),
            'post_sitetype' => isset($extraJson['site_type']) ? trim($extraJson['site_type']) : '',
            'post_sitedir' => $pick($row, 'sitedir'),
            'post_sitedeploy' => $pick($row, 'deploy'),
            'post_sitehostip' => $pick($row, 'hostip'),
            'local_deploy' => $pick($row, 'local_deploy'),
            'local_hostip' => $pick($row, 'local_hostip'),
            'post_description' => $pick($row, 'site_subtitle'),
            'post_status' => $pick($row, 'status'),
            'post_uuid' => '',
        ];
    }

    public static function buildSiteJson(array $post)
    {
        $siteJson = json_decode(isset($post['post_json']) ? $post['post_json'] : '', true);
        if (!is_array($siteJson)) {
            $siteJson = [];
        }
        $pick = function (array $post, $key) {
            return isset($post[$key]) ? $post[$key] : null;
        };
        $siteJson['git_name'] = $pick($post, 'post_gitname');
        $siteJson['post_uuid'] = $pick($post, 'post_uuid');
        $siteJson['git_account'] = $pick($post, 'post_gitaccount');
        $siteJson['domain'] = $pick($post, 'post_domain');
        $siteJson['site_title'] = htmlspecialchars(strip_tags((string)$pick($post, 'post_sitetitle')));
        $siteJson['site_logo'] = $pick($post, 'post_sitelogo');
        $siteJson['languages'] = $pick($post, 'post_lang');
        $siteJson['sns_id'] = $pick($post, 'post_sns_id');
        $siteJson['topnav_menus'] = $pick($post, 'post_topnavmenus');
        $siteJson['keyword'] = $pick($post, 'post_keyword');
        $siteJson['theme_name'] = $pick($post, 'post_gitname');
        $siteJson['theme_type'] = $pick($post, 'post_themetype');
        $siteJson['site_type'] = $pick($post, 'post_sitetype');
        $siteJson['sitedir'] = $pick($post, 'post_sitedir');
        $siteJson['deploy'] = $pick($post, 'post_sitedeploy');
        $siteJson['hostip'] = $pick($post, 'post_sitehostip');
        $siteJson['local_deploy'] = $pick($post, 'local_deploy');
        $siteJson['local_hostip'] = $pick($post, 'local_hostip');
        $siteJson['site_subtitle'] = htmlspecialchars(strip_tags((string)$pick($post, 'post_description')));
        $siteJson['status'] = $pick($post, 'post_status');
        return $siteJson;
    }

    public static function buildContent(array $post, array $siteJson)
    {
        $content = array_fill_keys([
            'ctx_id', 'git_name', 'git_account', 'domain', 'site_title', 'site_subtitle',
            'site_logo', 'languages', 'sns_id', 'topnav_menus', 'keyword', 'theme_name',
            'theme_type', 'site_type', 'sitedir', 'deploy', 'hostip', 'local_deploy',
            'local_hostip', 'status', 'json', 'setupNum', 'post_uuid',
        ], '');
        foreach ($post as $key => $value) {
            switch ($key) {
                case 'post_uuid':
                    $content['post_uuid'] = trim((string)$value);
                    $content['ctx_id'] = trim((string)$value);
                    break;
                case 'post_gitname':
                    $content['git_name'] = trim((string)$value);
                    break;
                case 'post_gitaccount':
                    $content['git_account'] = trim((string)$value);
                    break;
                case 'post_domain':
                    $content['domain'] = trim((string)$value);
                    break;
                case 'post_keyword':
                    $content['keyword'] = trim((string)$value);
                    break;
                case 'post_sitetitle':
                    $content['site_title'] = htmlspecialchars(strip_tags(trim((string)$value)));
                    break;
                case 'post_description':
                    $content['site_subtitle'] = htmlspecialchars(strip_tags(trim((string)$value)));
                    break;
                case 'post_sitelogo':
                    $content['site_logo'] = trim((string)$value);
                    break;
                case 'post_sitedir':
                    $content['sitedir'] = trim((string)$value);
                    break;
                case 'post_sitedeploy':
                    $content['deploy'] = trim((string)$value);
                    break;
                case 'post_sitehostip':
                    $content['hostip'] = trim((string)$value);
                    break;
                case 'local_deploy':
                    $content['local_deploy'] = trim((string)$value);
                    break;
                case 'local_hostip':
                    $content['local_hostip'] = trim((string)$value);
                    break;
                case 'post_lang':
                    $content['languages'] = trim((string)$value);
                    break;
                case 'post_sns_id':
                    $content['sns_id'] = trim((string)$value);
                    break;
                case 'post_topnavmenus':
                    $content['topnav_menus'] = trim((string)$value);
                    break;
                case 'post_themename':
                    $content['theme_name'] = trim((string)$value);
                    break;
                case 'post_themetype':
                    $content['theme_type'] = trim((string)$value);
                    break;
                case 'post_sitetype':
                    $content['site_type'] = trim((string)$value);
                    break;
                case 'post_status':
                    $content['status'] = trim((string)$value);
                    break;
                case 'post_json':
                    $content['json'] = trim((string)$value);
                    break;
                case 'setupNum':
                    $content['setupNum'] = trim((string)$value);
                    break;
                default:
                    break;
            }
        }
        $content['json'] = json_encode($siteJson);
        return $content;
    }

    public static function saveBackup(array $content, $dataDir)
    {
        $saveDir = $dataDir . '/sitebulkops';
        if (!is_dir($saveDir)) {
            mkdir($saveDir, 0755, true);
        }
        $ctxId = isset($content['post_uuid']) ? $content['post_uuid'] : uuid();
        file_put_contents($saveDir . '/' . $ctxId . '.json', $content['json']);
        return $ctxId;
    }
}
