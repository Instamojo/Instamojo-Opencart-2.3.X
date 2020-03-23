<?php
include __DIR__ . DIRECTORY_SEPARATOR . "ValidationRules.php";

class Validator {

        private $validation_rules_object = [];
        private $validation_type = null;
        private $error = [];
    
        public function __construct()
	{        
            $this->validation_rules_object = new ValidationRules();
        }
        
        public function set_validation_type ($validation_type) 
        {
            $this->validation_type = $validation_type;
        }
        
        public function get_validation_type()
        {
            return $this->validation_type;
        }
        
        public function reset_validation_type()
        {
            $this->validation_type = null;
            $this->error = [];
        }
        
        public function set_error($error, $field_name = '')
        {
            if (isset($field_name)) $this->error[$field_name][] = $error;
            else $this->error[] = $error;
        }

        public function get_error($field_name) 
        {
            return $this->error[$field_name];
        }
        
        public function get_validation_errors()
        {
            return $this->error;
        }

        public function validate($get_data_to_validate = [], $post_data_to_validate = [])
        {
            $data_to_validate = array_merge($get_data_to_validate, $post_data_to_validate);
            $validation_type = $this->validation_type;
            $this->reset_validation_type();

            if ($validation_type == null) { 
                $this->set_error('Missing validation_type');
                return false;
            }
            
            $validation_rules = $this->validation_rules_object->get_validation_rule($validation_type);
            
            if (!isset($validation_rules)) { 
                $this->set_error('Missing validation rule for : '. $validation_type);

                return false;
            }

            $validation_rules_field_name = array_keys($validation_rules);
            foreach ($validation_rules as $field_name => $validation_rule) {
                if (!$this->is_field_present($field_name, $validation_rules_field_name)) continue;
                if (!$this->validate_required($field_name, $data_to_validate[$field_name], $validation_rule['REQUIRED'])) continue;
                if (!empty($data_to_validate[$field_name])) {
                    $this->validate_data_type($validation_rule['DATA_TYPE'], $field_name, $data_to_validate[$field_name]);
                }                
                if (isset($validation_rule['DATA_IN'])) {
                    $this->validate_value_in($field_name, $data_to_validate[$field_name], $validation_rule['DATA_IN'], $validation_rule['REQUIRED']);
                }
            }

            return count($this->error) ? false : true;
        }

        private function is_field_present($field_name, $validation_rules_fields)
        {
            if (!in_array($field_name, $validation_rules_fields)) {
                $this->set_error('Missing fields: '. $field_name );

                return false;
            }
            
            return true;
        }

        private function validate_data_type($data_type, $field_name, $field_value)
        {
            switch ($data_type) {

                case 'string':
                case 'integer' :
                    if (gettype($field_value) !== $data_type) {
                        $this->set_error('Invalid data type, expected data type is: '.$data_type, $field_name);
                        
                        return false;
                    }
                    break;

                case 'boolean' :
                    if (gettype($field_value) !== $data_type) {
                        $this->set_error('Invalid data type, expected data type is: '.$data_type, $field_name);
                        
                        return false;
                    }
                    break;

                case 'amount':
                        if ((gettype($field_value) !== 'double' &&  gettype($field_value) !== 'integer')) {
                            $this->set_error('Invalid data type for "'.$field_name.'", expected data type is: '.$data_type.' (Interger/Float)', $field_name);
                            
                            return false;
                        }
                    break;

                case 'datetime' :
                    if (!DateTime::createFromFormat('Y-m-d\TH:i:s', $field_value) instanceof DateTime) {
                        $this->set_error('Invalid datatime type for '.$field_name.', expected data of type is: '.$data_type, $field_name);
                            
                        return false;
                    }
                    break;
                    
                case 'email':
                    if (!filter_var($field_value, FILTER_VALIDATE_EMAIL)) {
                        $this->set_error($field_value.'Invalid data type for "'.$field_name.'", expected data type is: '.$data_type);
                        return false;
                    }
                    break;;
                    
                case 'url':
                    if (!filter_var($field_value, FILTER_VALIDATE_URL)) {
                         $this->set_error('Invalid data type for "'.$field_name.'", expected data type is: '.$data_type);

                        return false;
                    }
            }
            
            return true;
        }
        
        private function validate_required($field_name, $field_value, $is_required)
        {
            if ($is_required) {
                if (empty($field_value)) {
                    $this->set_error('Field is required: '. $field_name, $field_name);
                    
                    return false;
                }
            }

            return true;
        }
        
        private function validate_value_in($field_name, $field_value, $field_possible_value, $is_required)
        {
            if ($is_required) {
                if (!in_array($field_value, $field_possible_value)) {
                    $this->set_error('Field value for "'.$field_name.'" is invalid, possible value could be from : '. implode(', ', $field_possible_value), $field_name);
                    
                    return false;
                }
            }

            return true;
        }
}