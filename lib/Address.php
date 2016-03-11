<?php

namespace Voltio\Locale;

class Address
{
    protected $locale;
    protected static $data_url = 'http://i18napis.appspot.com/address/data';

    private $map = array();

    public static function fetchLocales(callable $progress = null)
    {
        $locales = json_decode(file_get_contents(self::$data_url));
        if (isset($locales->countries)) {
            $countries = explode('~', $locales->countries);
            $total = count($countries);
            $current = 0;
            $data_dir = __DIR__ . '/i18n';

            // also fetch special ZZ location
            $countries[] = 'ZZ';
            $meta = array();

            $names = include  __DIR__ . '/countries.php';

            foreach ($countries as $country) {
                $file = $data_dir . '/' . $country . '.json';
                $data = file_get_contents(self::$data_url . '/' . $country);
                file_put_contents($file, $data);
                $data = json_decode($data);
                if ($country !== 'ZZ') {
                    $meta[] = array(
                        'code' => $country,
                        'name' => isset($names[$country]) ?
                            $names[$country] : (isset($data->name) ?
                                ucwords(strtolower($data->name)) : null),
                    );
                }
                // TODO: fetch sub_keys
                if (is_callable($progress)) {
                    $progress(array('current' => $current++, 'total' => $total, 'file' => $file));
                }
            }
            // save meta data
            file_put_contents($data_dir . '/meta.json', json_encode($meta));
        }
    }

    protected function buildMap()
    {
        $input = array();
        $map = array(
            'name'          => 'N',
            'organisation'  => 'O',
            'street'        => 'A',
            'sort'          => 'X',
            'state'         => 'S',
            'sublocality'   => 'D',
            'locality'      => 'C',
            'zip'           => 'Z',
        );
        // rename fields
        foreach ($this->locale as $key => $value) {
            if ($end = strpos($key, '_name_type', 2)) {
                $field = substr($key, 0, $end);
                if ($value !== $field) {
                    $map[$value] = $map[isset($this->map[$field]) ? $this->map[$field] : $field];
                    unset($map[$field]);
                }
            }
        }
        $map = array_flip($map);
        $this->map = $map;
    }

    public function setLocale($locale)
    {
        $file = __DIR__ . '/i18n/' . strtoupper($locale) . '.json';
        $defaultsFile = __DIR__ . '/i18n/ZZ.json';
        $defaultMeta = array();
        $meta = array();
        if (file_exists($defaultsFile)) {
            $defaultMeta = json_decode(file_get_contents($defaultsFile), true);
        }
        if (file_exists($file)) {
            $meta = json_decode(file_get_contents($file), true);
        }
        $meta = array_merge($defaultMeta, $meta);
        if (count($meta)) {
            $this->locale = $meta;
            $this->buildMap();
            return true;
        } else {
            throw new \Exception();
        }

    }

    public function getCountryList()
    {
        $file = __DIR__ . '/i18n/meta.json';
        $meta = json_decode(file_get_contents($file), true);
        return $meta;
    }

    public function getCountryCodes()
    {
        $countries = include __DIR__ . '/countries.php';
        return array_keys($countries);
    }

    public function getCountryName($code = null)
    {
        if (is_null($code) && $this->locale) {
            $code = $this->locale['key'];
        }

        $code = strtoupper($code);

        $metaFile = __DIR__ . '/i18n/meta.json';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if (isset($meta[$code])) {
                return $meta['country'];
            }
        }

        $file = __DIR__ . '/i18n/' . strtoupper($code) . '.json';
        if (file_exists($file)) {
            $meta = json_decode(file_get_contents($file), true);
            if (is_array($meta)) {
                return ucwords(strtolower($meta['name']));
            } else {
                throw new \Exception();
            }
        } else {
            throw new \Exception();
        }
    }

    public function getFormattedAddress($address, $delimiter = null)
    {
        if (isset($this->locale['fmt'])) {
            $address_format = $this->locale['fmt'];

            $formatted_address = $address_format;

            foreach ($this->map as $key => $value) {
                $value = $this->snakeToCamel($value);
                $formatted_address = str_replace('%' . $key, $address[$value], $formatted_address);
            }

            $formatted_address = preg_replace('((\%n)+)', '%n', $formatted_address);
            if (strpos($formatted_address, '%n') === 0) {
                $formatted_address = substr($formatted_address, 2);
            }

            if ($delimiter) {
                return trim(str_replace('%n', $delimiter, $formatted_address));
            }
            return explode("%n", $formatted_address);
        } else {
            throw new \Exception();
        }
    }

    public function getAddressInfo()
    {
        $return = array();
        if (isset($this->locale['fmt']))
        {
            $address_format_array = explode("%", $this->locale['fmt']);
            $required = isset($this->locale['fmt']) ? str_split($this->locale['require']) : array();
            foreach($address_format_array as $key => $value )
            {
                $value = substr($value, 0, 1);
                if(!empty($value) && isset($this->map[$value]))
                {
                    $return[]= array(
                        'field' => $this->snakeToCamel($this->map[$value]),
                        'required' => in_array($value, $required),
                    );
                }
            }
            return $return;
        } else {
            throw new \Exception();
        }
    }

    public function getPostalCodeFieldName()
    {
        return $this->locale['zip_name_type'];
    }

    /**
     * @param array $address
     * @return bool
     */
    public function isValidPostalCode($address) // TODO: test and extend to support subdivisions
    {
        $fullPattern = $this->locale['zip'];
        $field = $this->locale['zip_name_type'];
        preg_match('/' . $fullPattern . '/i', $address[$field], $matches);
        if (empty($matches[0]) || $matches[0] != $address[$field]) {
            return false;
        }
        return true;
    }

    /**
     * Replaces underscores with spaces, uppercases the first letters of each word,
     * lowercases the very first letter, then strips the spaces
     * @param string $val String to be converted
     * @return string     Converted string
     */
    protected function snakeToCamel($val)
    {
        return str_replace(' ', '', lcfirst(ucwords(str_replace('_', ' ', $val))));
    }

}
