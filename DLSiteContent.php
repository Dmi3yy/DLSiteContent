<?php namespace EvolutionCMS\Custom;

use \EvolutionCMS\Models\SiteContent;
use \EvolutionCMS\Models\SiteTmplvar;
use \EvolutionCMS\Models\SiteTmplvarContentvalue;
use \Illuminate\Support\Facades\DB;

class DLSiteContent extends SiteContent
{

    public function scopePublished($query)
    {
        return $query->where('published', '1');
    }

    public function scopeUnpublished($query)
    {
        return $query->where('published', '0');
    }

    public function scopeDepth($query, $parents = '', $depth = 1, $showParent = false)
    {
        $parents = explode(',', $parents);
        if (count($parents) > 0) {
            $ids = static::getIDs($parents, $depth);
            if (!empty($ids)) {
                $query = $query->whereIn('parent', $ids);
                if (!$showParent) {
                    $query = $query->whereNotIn('id', $ids);
                }
            }
        }
        return $query;
    }

    public static function getIDs($parents = array(), $depth = 1, $result = array())
    {
        $result = array_merge($result, $parents);
        if ($depth <= 1) return $result;
        $ids = static::whereIn('parent', $parents)->where('isfolder', '=', '1')->get()->pluck('id')->toArray();
        if (!empty($ids)) {
            $depth--;
            $result = static::getIDs($ids, $depth, $result);
        } else {
            return $result;
        }
        return $result;
    }

    public function scopeWithTVs($query, $tvList = array())
    {
        if (!empty($tvList)) {
            $query->select('site_content.*');
            $tvList = array_unique($tvList);
            $tvListWithDefaults = [];
            foreach ($tvList as $v) {
                $tmp = explode(':', $v, 2);
                $tvListWithDefaults[$tmp[0]] = !empty($tmp[1]) ? trim($tmp[1]) : '';
            }
            $tvs = SiteTmplvar::whereIn('name', array_keys($tvListWithDefaults))->get()->pluck('id', 'name')->toArray();
            foreach ($tvs as $tvname => $tvid) {
                $query = $query->leftJoin('site_tmplvar_contentvalues as tv_' . $tvname, function ($join) use ($tvid, $tvname) {
                    $join->on('site_content.id', '=', 'tv_' . $tvname . '.contentid')->where('tv_' . $tvname . '.tmplvarid', '=', $tvid);
                });
                if (!empty($tvListWithDefaults[$tvname]) && $tvListWithDefaults[$tvname] == 'd') {
                    $query = $query->leftJoin('site_tmplvars as tvd_' . $tvname, function ($join) use ($tvid, $tvname) {
                        $join->where('tvd_' . $tvname . '.id', '=', $tvid);
                    });

                }
            }
            $query->groupBy('site_content.id');
        }
        return $query;
    }

    public function scopeTvFilter($query, $filters = '', $outerSep = ';', $innerSep = ':')
    {
        //todo tvd
        $prefix = EvolutionCMS()->getDatabase()->getConfig('prefix');
        $filters = explode($outerSep, trim($filters));
        foreach ($filters as $filter) {
            if (empty($filter)) break;
            $parts = explode($innerSep, $filter, 5);
            $type = $parts[0];
            $tvname = $parts[1];
            $op = $parts[2];
            $value = !empty($parts[3]) ? $parts[3] : '';
            $cast = !empty($parts[4]) ? $parts[4] : '';
            $field = 'tv_' . $tvname . '.value';
            if ($type == 'tvd') {
                $field = DB::Raw("IFNULL(`" . $prefix . "tv_" . $tvname . "`.`value`, `" . $prefix . "tvd_" . $tvname . "`.`default_text`)");
            }
            switch(true) {
                case ($op == 'in'):
                    $query = $query->whereIn($field, explode(',', $value));
                    break;
                case ($op == 'not_in'):
                    $query = $query->whereNotIn($field, explode(',', $value));
                    break;
                case ($op == 'like'):
                    $query = $query->where($field, $op, '%' . $value . '%');
                    break;
                case ($op == 'like-r'):
                    $query = $query->where($field, $op, $value . '%');
                    break;
                case ($op == 'like-l'):
                    $query = $query->where($field, $op, '%' . $value);
                    break;
                case ($op == 'isnull'):
                case ($op == 'null'):
                    $query = $query->whereNull($field);
                    break;
                case ($op == 'isnotnull'):
                case ($op == '!null'):
                    $query = $query->whereNotNull($field);
                    break;
                case ($cast == 'UNSIGNED'):
                case ($cast == 'SIGNED'):
                case (strpos($cast, 'DECIMAL') !== false):
                    if ($type == 'tvd') {
                        $query = $query->whereRaw("CAST(IFNULL(`" . $prefix . "tv_" . $tvname . "`.`value`, `" . $prefix . "tvd_" . $tvname . "`.`default_text`) AS " . $cast . " ) " . $op . " " . $value);
                    } else {
                        $query = $query->whereRaw("CAST(`" . $prefix . 'tv_' . $tvname . "`.`value` AS " . $cast . " ) " . $op . " " . $value);
                    }
                    break;
                default:
                    $query = $query->where($field, $op, $value);
                    break;
            }
        }
        return $query;
    }

    public function scopeTvOrderBy($query, $orderBy = '')
    {
        $prefix = EvolutionCMS()->getDatabase()->getConfig('prefix');
        $orderBy = explode(',', trim($orderBy));
        foreach ($orderBy as $parts) {
            if (empty(trim($parts))) return;
            $part = explode(' ', trim($parts), 3);
            $tvname = $part[0];
            $sortDir = !empty($part[1]) ? $part[1] : 'desc';
            $cast = !empty($part[2]) ? $part[2] : '';
            switch (true) {
                case ($cast == 'UNSIGNED'):
                case ($cast == 'SIGNED'):
                case (strpos($cast, 'DECIMAL') !== false):
                    $query = $query->orderByRaw("CAST(" . $prefix . 'tv_' . $tvname . ".value AS " . $cast . ") " . $sortDir);
                    break;
                default:
                    $query = $query->orderBy('tv_' . $tvname . ".value", $sortDir);
                    break;
            }
        }
        return $query;
    }

    public static function tvList($docs, $tvList = array())
    {
        $docsTV = array();
        if (empty($docs)) {
            return array();
        } else if (empty($tvList)) {
            return array();
        } else {
            $ids = $docs->pluck('id')->toArray();
            $tvs = SiteTmplvar::whereIn('name', $tvList)->get();
            $tvNames = $tvs->pluck('default_text', 'name')->toArray();
            $tvIds = $tvs->pluck('name', 'id')->toArray();
            $tvValues = SiteTmplvarContentvalue::whereIn('contentid', $ids)->whereIn('tmplvarid', array_keys($tvIds))->get()->toArray();
            foreach ($tvValues as $tv) {
                if (empty($tv['value']) && !empty($tvNames[$tvIds [$tv['tmplvarid']] ] )) {
                    $tv['value'] = $tvNames[ $tvIds[ $tv['tmplvarid'] ] ];
                }
                unset($tv['id']);
                $docsTV[ $tv['contentid'] ][ $tv['tmplvarid'] ] = $tv;
            }
            foreach ($ids as $docid) {
                foreach ($tvIds as $tvid => $tvname) {
                    if (empty($docsTV[$docid][$tvid])) {
                        $docsTV[$docid][$tvid] = array('tmplvarid' => $tvid, 'contentid' => $docid, 'value' => $tvNames[$tvIds [$tvid] ]);
                    }
                }
            }
        }
        if (!empty($docsTV)) {
            $tmp = array();
            foreach ($docsTV as $docid => $tvs) {
                foreach ($tvs as $tvid => $tv) {
                    $tmp[$docid][ $tvIds[$tvid] ] = $tv['value'];
                }
            }
            $docsTV = $tmp;
        }
        return $docsTV;
    }

}
