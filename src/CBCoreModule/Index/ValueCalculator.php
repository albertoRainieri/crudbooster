<?php

namespace crocodicstudio\crudbooster\CBCoreModule\Index;

class ValueCalculator
{
    /**
     * @param $col
     * @param $row
     * @param $table
     * @return string
     */
    function calculate($col, $row, $table)
    {
        $value = @$row->{$col['field']};
        $title = @$row->{$this->cb->title_field};
        $label = $col['label'];

        if (isset($col['image'])) {
            $value = $this->image($col, $table, $value, $label, $title);
        }

        if (isset($col['download'])) {
            list($col, $value) = $this->download($col, $value);
        }

        if ($col['str_limit']) {
            $value = $this->str_limit($col, $value);
        }

        if ($col['nl2br']) {
            $value = nl2br($value);
        }

        if (isset($col['callback'])) {
            $value = call_user_func($col['callback'], $row);
        }

        return $this->includeLabels($value);
    }

    /**
     * @param $col
     * @param $table
     * @param $value
     * @param $label
     * @param $title
     * @return array
     */
    private function image($table, $value, $label, $title)
    {
        if ($value == '') {
            return "<a  data-lightbox='roadtrip' rel='group_{{$table}}' title='$label: $title' href='".asset('vendor/crudbooster/avatar.jpg')."'><img width='40px' height='40px' src='".asset('vendor/crudbooster/avatar.jpg')."'/></a>";
        }
        $pic = (strpos($value, 'http://') !== false) ? $value : asset($value);
        return "<a data-lightbox='roadtrip'  rel='group_{{$table}}' title='$label: $title' href='".$pic."'><img width='40px' height='40px' src='".$pic."'/></a>";
    }

    /**
     * @param $col
     * @param $value
     * @return array
     */
    private function download($col, $value)
    {
        $url = (strpos($value, 'http://')) ? $value : asset($value).'?download=1';
        if ($value) {
            $value = "<a class='btn btn-xs btn-primary' href='$url' target='_blank' title='Download File'><i class='fa fa-download'></i> Download</a>";
        } else {
            $value = " - ";
        }

        return [$col, $value];
    }

    /**
     * @param $col
     * @param $value
     * @return string
     */
    private function str_limit($col, $value)
    {
        return str_limit(trim(strip_tags($value)), $col['str_limit']);
    }

    /**
     * @param $value
     * @return string
     */
    private function includeLabels($value)
    {
        $datavalue = @unserialize($value);
        if (!$datavalue) {
            return $value;
        }
        $preValue = [];
        foreach ($datavalue as $d) {
            if ($d['label']) {
                $preValue[] = $d['label'];
            }
        }
        if (!empty($preValue)) {
            $value = implode(", ", $preValue);
        }

        return $value;
    }
}