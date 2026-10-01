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

    /**
     * post_* 字段 -> sitetopic/siteops.json 镜像键的映射。
     * 第二项为 true 表示该值需先 strip_tags + htmlspecialchars。
     * buildSiteJson（整行）与 buildJsonForPartial（部分更新）共用此表，
     * 保证两条路径产出的 json 结构一致。
     */
    const JSON_FIELD_MAP = [
        'git_name' => 'post_gitname',
        'post_uuid' => 'post_uuid',
        'git_account' => 'post_gitaccount',
        'domain' => 'post_domain',
        'site_title' => ['post_sitetitle', true],
        'site_logo' => 'post_sitelogo',
        'languages' => 'post_lang',
        'sns_id' => 'post_sns_id',
        'topnav_menus' => 'post_topnavmenus',
        'keyword' => 'post_keyword',
        'theme_name' => 'post_gitname',
        'theme_type' => 'post_themetype',
        'site_type' => 'post_sitetype',
        'sitedir' => 'post_sitedir',
        'deploy' => 'post_sitedeploy',
        'hostip' => 'post_sitehostip',
        'local_deploy' => 'local_deploy',
        'local_hostip' => 'local_hostip',
        'site_subtitle' => ['post_description', true],
        'status' => 'post_status',
    ];

    /**
     * json 镜像键 -> siteops 列名。供部分更新后重算 json 使用。
     * 保持与 buildSiteJson 一致的取值来源（theme_name 沿用历史行为取 git_name）。
     */
    const JSON_COLUMN_MAP = [
        'git_name' => 'git_name',
        'git_account' => 'git_account',
        'domain' => 'domain',
        'site_title' => 'site_title',
        'site_subtitle' => 'site_subtitle',
        'site_logo' => 'site_logo',
        'languages' => 'languages',
        'sns_id' => 'sns_id',
        'topnav_menus' => 'topnav_menus',
        'keyword' => 'keyword',
        'theme_name' => 'git_name',
        'theme_type' => 'theme_type',
        'sitedir' => 'sitedir',
        'deploy' => 'deploy',
        'hostip' => 'hostip',
        'local_deploy' => 'local_deploy',
        'local_hostip' => 'local_hostip',
        'status' => 'status',
    ];

    public static function buildSiteJson(array $post)
    {
        $siteJson = json_decode(isset($post['post_json']) ? $post['post_json'] : '', true);
        if (!is_array($siteJson)) {
            $siteJson = [];
        }
        foreach (self::JSON_FIELD_MAP as $jsonKey => $spec) {
            $postKey = is_array($spec) ? $spec[0] : $spec;
            $sanitize = is_array($spec) && !empty($spec[1]);
            $value = isset($post[$postKey]) ? $post[$postKey] : null;
            $siteJson[$jsonKey] = $sanitize
                ? htmlspecialchars(strip_tags((string)$value))
                : $value;
        }
        return $siteJson;
    }

    /**
     * 部分更新后按合并结果重算 json 镜像。
     *
     * 入参是 buildContent 产出的列数组（值已完成 strip_tags/htmlspecialchars），
     * 因此这里不再重复转义，避免二次转义。json 专属键
     * （site_type / post_uuid 等不落列的字段）从 $existingJson 保留。
     */
    public static function buildJsonForPartial(array $mergedContent, array $existingJson)
    {
        $siteJson = $existingJson;
        foreach (self::JSON_COLUMN_MAP as $jsonKey => $column) {
            $siteJson[$jsonKey] = isset($mergedContent[$column]) ? $mergedContent[$column] : '';
        }
        return json_encode($siteJson);
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
