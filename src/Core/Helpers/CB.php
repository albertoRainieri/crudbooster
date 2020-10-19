<?php

namespace Crocodic\CrudBooster\Core\Helpers;

class CB
{
    use FileHandlingSupport, DbSupport, CacheSupport;

    public static function back($message, $type = 'warning')
    {
        return redirect()->back()->with(['message'=>$message,'type'=>$type]);
    }

    public static function redirect($to, $message, $type = 'success')
    {
        return redirect($to)->with(['message'=>$message,'type'=>$type]);
    }

    public static function currentMethod()
    {
        $action = str_replace("App\Http\Controllers", "", Route::currentRouteAction());
        $alloc = strpos($action, '@') + 1;
        return substr($action, $alloc);
    }

    public static function sendMail($config = [])
    {
        $to = $config['to'];
        $data = $config['data'];
        $template = $config['template'];

        $template = CB::first('cms_email_templates', ['slug' => $template]);
        $html = $template->content;
        foreach ($data as $key => $val) {
            $html = str_replace('['.$key.']', $val, $html);
            $template->subject = str_replace('['.$key.']', $val, $template->subject);
        }
        $subject = $template->subject;
        $attachments = ($config['attachments']) ?: [];

        if ($config['send_at'] != null) {
            $a = [];
            $a['send_at'] = $config['send_at'];
            $a['email_recipient'] = $to;
            $a['email_from_email'] = $template->from_email ?: CB::getSetting('email_sender');
            $a['email_from_name'] = $template->from_name ?: CB::getSetting('appname');
            $a['email_cc_email'] = $template->cc_email;
            $a['email_subject'] = $subject;
            $a['email_content'] = $html;
            $a['email_attachments'] = serialize($attachments);
            $a['is_sent'] = 0;
            DB::table('cms_email_queues')->insert($a);

            return true;
        }

        \Mail::send("crudbooster::emails.blank", ['content' => $html], function ($message) use ($to, $subject, $template, $attachments) {
            $message->priority(1);
            $message->to($to);

            if ($template->from_email) {
                $from_name = ($template->from_name) ?: CB::getSetting('appname');
                $message->from($template->from_email, $from_name);
            }

            if ($template->cc_email) {
                $message->cc($template->cc_email);
            }

            if (count($attachments)) {
                foreach ($attachments as $attachment) {
                    $message->attach($attachment);
                }
            }

            $message->subject($subject);
        });
    }

    public static function sendNotification($config = [])
    {
        $content = $config['content'];
        $to = $config['to'];
        $id_cms_users = $config['id_cms_users'];
        $id_cms_users = ($id_cms_users) ?: [CB::myId()];
        foreach ($id_cms_users as $id) {
            $a = [];
            $a['created_at'] = date('Y-m-d H:i:s');
            $a['id_cms_users'] = $id;
            $a['content'] = $content;
            $a['is_read'] = 0;
            $a['url'] = $to;
            DB::table('cms_notifications')->insert($a);
        }

        return true;
    }

    public static function sendFCM($regID = [], $data)
    {
        if (! $data['title'] || ! $data['content']) {
            return 'title , content null !';
        }

        $apikey = CB::getSetting('google_fcm_key');
        $url = 'https://fcm.googleapis.com/fcm/send';
        $fields = [
            'registration_ids' => $regID,
            'data' => $data,
            'content_available' => true,
            'notification' => [
                'sound' => 'default',
                'badge' => 0,
                'title' => trim(strip_tags($data['title'])),
                'body' => trim(strip_tags($data['content'])),
            ],
            'priority' => 'high',
        ];
        $headers = [
            'Authorization:key='.$apikey,
            'Content-Type:application/json',
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $chresult = curl_exec($ch);
        curl_close($ch);

        return $chresult;
    }

    public static function getTableColumns($table)
    {
        //$cols = DB::getSchemaBuilder()->getColumnListing($table);
        $table = CB::parseSqlTable($table);
        $cols = collect(DB::select('SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :database AND TABLE_NAME = :table', [
            'database' => $table['database'],
            'table' => $table['table'],
        ]))->map(function ($x) {
            return (array) $x;
        })->toArray();

        $result = [];
        $result = $cols;

        $new_result = [];
        foreach ($result as $ro) {
            $new_result[] = $ro['COLUMN_NAME'];
        }

        return $new_result;
    }

    public static function getNameTable($columns)
    {
        $name_col_candidate = config('crudbooster.NAME_FIELDS_CANDIDATE');
        $name_col_candidate = explode(',', $name_col_candidate);
        $name_col = '';
        foreach ($columns as $c) {
            foreach ($name_col_candidate as $cc) {
                if (strpos($c, $cc) !== false) {
                    $name_col = $c;
                    break;
                }
            }
            if ($name_col) {
                break;
            }
        }
        if ($name_col == '') {
            $name_col = 'id';
        }

        return $name_col;
    }

    public static function isExistsController($table)
    {
        $controllername = ucwords(str_replace('_', ' ', $table));
        $controllername = str_replace(' ', '', $controllername).'Controller';
        $path = base_path("app/Http/Controllers/");
        $path2 = base_path("app/Http/Controllers/ControllerMaster/");
        if (file_exists($path.'Admin'.$controllername.'.php') || file_exists($path2.'Admin'.$controllername.'.php') || file_exists($path2.$controllername.'.php')) {
            return true;
        } else {
            return false;
        }
    }

    public static function generateAPI($controller_name, $table_name, $permalink, $method_type = 'post')
    {
        $php = '
		<?php namespace App\Http\Controllers;

		use Session;
		use Request;
		use DB;
		use CRUDBooster;

		class Api'.$controller_name.'Controller extends \crocodicstudio\crudbooster\controllers\ApiController {

		    function __construct() {    
				$this->table       = "'.$table_name.'";        
				$this->permalink   = "'.$permalink.'";    
				$this->method_type = "'.$method_type.'";    
		    }
		';

        $php .= "\n".'
		    public function hook_before(&$postdata) {
		        //This method will be execute before run the main process

		    }';

        $php .= "\n".'
		    public function hook_query(&$query) {
		        //This method is to customize the sql query

		    }';

        $php .= "\n".'
		    public function hook_after($postdata,&$result) {
		        //This method will be execute after run the main process

		    }';

        $php .= "\n".'
		}
		';

        $php = trim($php);
        $path = base_path("app/Http/Controllers/");
        file_put_contents($path.'Api'.$controller_name.'Controller.php', $php);
    }

    public static function generateController($table, $name = null)
    {

        $exception = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $image_candidate = explode(',', config('crudbooster.IMAGE_FIELDS_CANDIDATE'));
        $password_candidate = explode(',', config('crudbooster.PASSWORD_FIELDS_CANDIDATE'));
        $phone_candidate = explode(',', config('crudbooster.PHONE_FIELDS_CANDIDATE'));
        $email_candidate = explode(',', config('crudbooster.EMAIL_FIELDS_CANDIDATE'));
        $name_candidate = explode(',', config('crudbooster.NAME_FIELDS_CANDIDATE'));
        $url_candidate = explode(',', config("crudbooster.URL_FIELDS_CANDIDATE"));

        $controllername = ucwords(str_replace('_', ' ', $table));
        $controllername = str_replace(' ', '', $controllername).'Controller';
        if ($name) {
            $controllername = ucwords(str_replace(['_', '-'], ' ', $name));
            $controllername = str_replace(' ', '', $controllername).'Controller';
        }

        $path = base_path("app/Http/Controllers/");
        $countSameFile = count(glob($path.'Admin'.$controllername.'.php'));

        if ($countSameFile != 0) {
            $suffix = $countSameFile;
            $controllername = ucwords(str_replace(['_', '-'], ' ', $name)).$suffix;
            $controllername = str_replace(' ', '', $controllername).'Controller';
        }

        $coloms = CB::getTableColumns($table);
        $name_col = CB::getNameTable($coloms);
        $pk = CB::pk($table);

        $button_table_action = 'TRUE';
        $button_action_style = "button_icon";
        $button_add = 'TRUE';
        $button_edit = 'TRUE';
        $button_delete = 'TRUE';
        $button_show = 'TRUE';
        $button_detail = 'TRUE';
        $button_filter = 'TRUE';
        $button_export = 'FALSE';
        $button_import = 'FALSE';
        $button_bulk_action = 'TRUE';
        $global_privilege = 'FALSE';

        $php = '
<?php namespace App\Http\Controllers;

	use Session;
	use Request;
	use DB;
	use CRUDBooster;

	class Admin'.$controllername.' extends \crocodicstudio\crudbooster\controllers\CBController {

	    public function cbInit() {
	    	# START CONFIGURATION DO NOT REMOVE THIS LINE
			$this->table 			   = "'.$table.'";	        
			$this->title_field         = "'.$name_col.'";
			$this->limit               = 20;
			$this->orderby             = "'.$pk.',desc";
			$this->show_numbering      = FALSE;
			$this->global_privilege    = '.$global_privilege.';	        
			$this->button_table_action = '.$button_table_action.';   
			$this->button_action_style = "'.$button_action_style.'";     
			$this->button_add          = '.$button_add.';
			$this->button_delete       = '.$button_delete.';
			$this->button_edit         = '.$button_edit.';
			$this->button_detail       = '.$button_detail.';
			$this->button_show         = '.$button_show.';
			$this->button_filter       = '.$button_filter.';        
			$this->button_export       = '.$button_export.';	        
			$this->button_import       = '.$button_import.';
			$this->button_bulk_action  = '.$button_bulk_action.';	
			$this->sidebar_mode		   = "normal"; //normal,mini,collapse,collapse-mini
			# END CONFIGURATION DO NOT REMOVE THIS LINE						      

			# START COLUMNS DO NOT REMOVE THIS LINE
	        $this->col = [];
	';
        $coloms_col = array_slice($coloms, 0, 8);
        foreach ($coloms_col as $c) {
            $label = str_replace("id_", "", $c);
            $label = ucwords(str_replace("_", " ", $label));
            $label = str_replace('Cms ', '', $label);
            $field = $c;

            if (in_array($field, $exception)) {
                continue;
            }

            if (array_search($field, $password_candidate) !== false) {
                continue;
            }

            if (substr($field, 0, 3) == 'id_') {
                $jointable = str_replace('id_', '', $field);
                $joincols = CB::getTableColumns($jointable);
                $joinname = CB::getNameTable($joincols);
                $php .= "\t\t".'$this->col[] = array("label"=>"'.$label.'","name"=>"'.$field.'","join"=>"'.$jointable.','.$joinname.'");'."\n";
            } elseif (substr($field, -3) == '_id') {
                $jointable = substr($field, 0, (strlen($field) - 3));
                $joincols = CB::getTableColumns($jointable);
                $joinname = CB::getNameTable($joincols);
                $php .= "\t\t".'$this->col[] = array("label"=>"'.$label.'","name"=>"'.$field.'","join"=>"'.$jointable.','.$joinname.'");'."\n";
            } else {
                $image = '';
                if (in_array($field, $image_candidate)) {
                    $image = ',"image"=>true';
                }
                $php .= "\t\t".'$this->col[] = array("label"=>"'.$label.'","name"=>"'.$field.'" '.$image.');'."\n";
            }
        }

        $php .= "\n\t\t\t# END COLUMNS DO NOT REMOVE THIS LINE";

        $php .= "\n\t\t\t# START FORM DO NOT REMOVE THIS LINE";
        $php .= "\n\t\t".'$this->form = [];'."\n";

        foreach ($coloms as $c) {
            $attribute = [];
            $validation = [];
            $validation[] = 'required';
            $placeholder = '';
            $help = '';

            $label = str_replace("id_", "", $c);
            $label = ucwords(str_replace("_", " ", $label));
            $field = $c;

            if (in_array($field, $exception)) {
                continue;
            }

            $typedata = CB::getFieldType($table, $field);

            switch ($typedata) {
                default:
                case 'varchar':
                case 'char':
                    $type = "text";
                    $validation[] = "min:1|max:255";
                    break;
                case 'text':
                case 'longtext':
                    $type = 'textarea';
                    $validation[] = "string|min:5|max:5000";
                    break;
                case 'date':
                    $type = 'date';
                    $validation[] = "date";
                    break;
                case 'datetime':
                case 'timestamp':
                    $type = 'datetime';
                    $validation[] = "date_format:Y-m-d H:i:s";
                    break;
                case 'time':
                    $type = 'time';
                    $validation[] = 'date_format:H:i:s';
                    break;
                case 'double':
                    $type = 'money';
                    $validation[] = "integer|min:0";
                    break;
                case 'int':
                case 'integer':
                    $type = 'number';
                    $validation[] = 'integer|min:0';
                    break;
            }

            if (substr($field, 0, 3) == 'id_') {
                $jointable = str_replace('id_', '', $field);
                $joincols = CB::getTableColumns($jointable);
                $joinname = CB::getNameTable($joincols);
                $attribute['datatable'] = $jointable.','.$joinname;
                $type = 'select2';
            }

            if (substr($field, -3) == '_id') {
                $jointable = str_replace('_id', '', $field);
                $joincols = CB::getTableColumns($jointable);
                $joinname = CB::getNameTable($joincols);
                $attribute['datatable'] = $jointable.','.$joinname;
                $type = 'select2';
            }

            if (substr($field, 0, 3) == 'is_') {
                $type = 'radio';
                $label_field = ucwords(substr($field, 3));
                $validation = ['required|integer'];
                $attribute['dataenum'] = ['1|'.$label_field, '0|Un-'.$label_field];
            }

            if (in_array($field, $password_candidate)) {
                $type = 'password';
                $validation = ['min:3', 'max:32'];
                $attribute['help'] = cbLang("text_default_help_password");
            }

            if (in_array($field, $image_candidate)) {
                $type = 'upload';
                $attribute['help'] = cbLang('text_default_help_upload');
                $validation = ['required|image|max:3000'];
            }

            if ($field == 'latitude') {
                $type = 'hidden';
            }
            if ($field == 'longitude') {
                $type = 'hidden';
            }

            if (in_array($field, $phone_candidate)) {
                $type = 'number';
                $validation = ['required', 'numeric'];
                $attribute['placeholder'] = cbLang('text_default_help_number');
            }

            if (in_array($field, $email_candidate)) {
                $type = 'email';
                $validation[] = 'email|unique:'.$table;
                $attribute['placeholder'] = cbLang('text_default_help_email');
            }

            if ($type == 'text' && in_array($field, $name_candidate)) {
                $attribute['placeholder'] = cbLang('text_default_help_text');
                $validation = ['required', 'string', 'min:3', 'max:70'];
            }

            if ($type == 'text' && in_array($field, $url_candidate)) {
                $validation = ['required', 'url'];
                $attribute['placeholder'] = cbLang('text_default_help_url');
            }

            $validation = implode('|', $validation);

            $php .= "\t\t";
            $php .= '$this->form[] = ["label"=>"'.$label.'","name"=>"'.$field.'","type"=>"'.$type.'","required"=>TRUE';

            if ($validation) {
                $php .= ',"validation"=>"'.$validation.'"';
            }

            if ($attribute) {
                foreach ($attribute as $key => $val) {
                    if (is_bool($val)) {
                        $val = ($val) ? "TRUE" : "FALSE";
                    } else {
                        $val = '"'.$val.'"';
                    }
                    $php .= ',"'.$key.'"=>'.$val;
                }
            }

            $php .= "];\n";
        }

        $php .= "\n\t\t\t# END FORM DO NOT REMOVE THIS LINE";

        $php .= '     

			/* 
	        | ---------------------------------------------------------------------- 
	        | Sub Module
	        | ----------------------------------------------------------------------     
			| @label          = Label of action 
			| @path           = Path of sub module
			| @foreign_key 	  = foreign key of sub table/module
			| @button_color   = Bootstrap Class (primary,success,warning,danger)
			| @button_icon    = Font Awesome Class  
			| @parent_columns = Sparate with comma, e.g : name,created_at
	        | 
	        */
	        $this->sub_module = array();


	        /* 
	        | ---------------------------------------------------------------------- 
	        | Add More Action Button / Menu
	        | ----------------------------------------------------------------------     
	        | @label       = Label of action 
	        | @url         = Target URL, you can use field alias. e.g : [id], [name], [title], etc
	        | @icon        = Font awesome class icon. e.g : fa fa-bars
	        | @color 	   = Default is primary. (primary, warning, succecss, info)     
	        | @showIf 	   = If condition when action show. Use field alias. e.g : [id] == 1
	        | 
	        */
	        $this->addaction = array();


	        /* 
	        | ---------------------------------------------------------------------- 
	        | Add More Button Selected
	        | ----------------------------------------------------------------------     
	        | @label       = Label of action 
	        | @icon 	   = Icon from fontawesome
	        | @name 	   = Name of button 
	        | Then about the action, you should code at actionButtonSelected method 
	        | 
	        */
	        $this->button_selected = array();

	                
	        /* 
	        | ---------------------------------------------------------------------- 
	        | Add alert message to this module at overheader
	        | ----------------------------------------------------------------------     
	        | @message = Text of message 
	        | @type    = warning,success,danger,info        
	        | 
	        */
	        $this->alert        = array();
	                

	        
	        /* 
	        | ---------------------------------------------------------------------- 
	        | Add more button to header button 
	        | ----------------------------------------------------------------------     
	        | @label = Name of button 
	        | @url   = URL Target
	        | @icon  = Icon from Awesome.
	        | 
	        */
	        $this->index_button = array();



	        /* 
	        | ---------------------------------------------------------------------- 
	        | Customize Table Row Color
	        | ----------------------------------------------------------------------     
	        | @condition = If condition. You may use field alias. E.g : [id] == 1
	        | @color = Default is none. You can use bootstrap success,info,warning,danger,primary.        
	        | 
	        */
	        $this->table_row_color = array();     	          

	        
	        /*
	        | ---------------------------------------------------------------------- 
	        | You may use this bellow array to add statistic at dashboard 
	        | ---------------------------------------------------------------------- 
	        | @label, @count, @icon, @color 
	        |
	        */
	        $this->index_statistic = array();



	        /*
	        | ---------------------------------------------------------------------- 
	        | Add javascript at body 
	        | ---------------------------------------------------------------------- 
	        | javascript code in the variable 
	        | $this->script_js = "function() { ... }";
	        |
	        */
	        $this->script_js = NULL;


            /*
	        | ---------------------------------------------------------------------- 
	        | Include HTML Code before index table 
	        | ---------------------------------------------------------------------- 
	        | html code to display it before index table
	        | $this->pre_index_html = "<p>test</p>";
	        |
	        */
	        $this->pre_index_html = null;
	        
	        
	        
	        /*
	        | ---------------------------------------------------------------------- 
	        | Include HTML Code after index table 
	        | ---------------------------------------------------------------------- 
	        | html code to display it after index table
	        | $this->post_index_html = "<p>test</p>";
	        |
	        */
	        $this->post_index_html = null;
	        
	        
	        
	        /*
	        | ---------------------------------------------------------------------- 
	        | Include Javascript File 
	        | ---------------------------------------------------------------------- 
	        | URL of your javascript each array 
	        | $this->load_js[] = asset("myfile.js");
	        |
	        */
	        $this->load_js = array();
	        
	        
	        
	        /*
	        | ---------------------------------------------------------------------- 
	        | Add css style at body 
	        | ---------------------------------------------------------------------- 
	        | css code in the variable 
	        | $this->style_css = ".style{....}";
	        |
	        */
	        $this->style_css = NULL;
	        
	        
	        
	        /*
	        | ---------------------------------------------------------------------- 
	        | Include css File 
	        | ---------------------------------------------------------------------- 
	        | URL of your css each array 
	        | $this->load_css[] = asset("myfile.css");
	        |
	        */
	        $this->load_css = array();
	        
	        
	    }


	    /*
	    | ---------------------------------------------------------------------- 
	    | Hook for button selected
	    | ---------------------------------------------------------------------- 
	    | @id_selected = the id selected
	    | @button_name = the name of button
	    |
	    */
	    public function actionButtonSelected($id_selected,$button_name) {
	        //Your code here
	            
	    }


	    /*
	    | ---------------------------------------------------------------------- 
	    | Hook for manipulate query of index result 
	    | ---------------------------------------------------------------------- 
	    | @query = current sql query 
	    |
	    */
	    public function hook_query_index(&$query) {
	        //Your code here
	            
	    }

	    /*
	    | ---------------------------------------------------------------------- 
	    | Hook for manipulate row of index table html 
	    | ---------------------------------------------------------------------- 
	    |
	    */    
	    public function hook_row_index($column_index,&$column_value) {	        
	    	//Your code here
	    }

	    /*
	    | ---------------------------------------------------------------------- 
	    | Hook for manipulate data input before add data is execute
	    | ---------------------------------------------------------------------- 
	    | @arr
	    |
	    */
	    public function hook_before_add(&$postdata) {        
	        //Your code here

	    }

	    /* 
	    | ---------------------------------------------------------------------- 
	    | Hook for execute command after add public static function called 
	    | ---------------------------------------------------------------------- 
	    | @id = last insert id
	    | 
	    */
	    public function hook_after_add($id) {        
	        //Your code here

	    }

	    /* 
	    | ---------------------------------------------------------------------- 
	    | Hook for manipulate data input before update data is execute
	    | ---------------------------------------------------------------------- 
	    | @postdata = input post data 
	    | @id       = current id 
	    | 
	    */
	    public function hook_before_edit(&$postdata,$id) {        
	        //Your code here

	    }

	    /* 
	    | ---------------------------------------------------------------------- 
	    | Hook for execute command after edit public static function called
	    | ----------------------------------------------------------------------     
	    | @id       = current id 
	    | 
	    */
	    public function hook_after_edit($id) {
	        //Your code here 

	    }

	    /* 
	    | ---------------------------------------------------------------------- 
	    | Hook for execute command before delete public static function called
	    | ----------------------------------------------------------------------     
	    | @id       = current id 
	    | 
	    */
	    public function hook_before_delete($id) {
	        //Your code here

	    }

	    /* 
	    | ---------------------------------------------------------------------- 
	    | Hook for execute command after delete public static function called
	    | ----------------------------------------------------------------------     
	    | @id       = current id 
	    | 
	    */
	    public function hook_after_delete($id) {
	        //Your code here

	    }



	    //By the way, you can still create your own method in here... :) 


	}
	        ';

        $php = trim($php);

        //create file controller
        file_put_contents($path.'Admin'.$controllername.'.php', $php);

        return 'Admin'.$controllername;
    }

    /*
    | --------------------------------------------------------------------------------------------------------------
    | Alternate route for Laravel Route::controller
    | --------------------------------------------------------------------------------------------------------------
    | $prefix       = path of route
    | $controller   = controller name
    | $namespace    = namespace of controller (optional)
    |
    */
    public static function routeController($prefix, $controller, $namespace = null)
    {

        $prefix = trim($prefix, '/').'/';

        $namespace = ($namespace) ?: 'App\Http\Controllers';

        try {
            Route::get($prefix, ['uses' => $controller.'@getIndex', 'as' => $controller.'GetIndex']);

            $controller_class = new \ReflectionClass($namespace.'\\'.$controller);
            $controller_methods = $controller_class->getMethods(\ReflectionMethod::IS_PUBLIC);
            $wildcards = '/{one?}/{two?}/{three?}/{four?}/{five?}';
            foreach ($controller_methods as $method) {

                if ($method->class != 'Illuminate\Routing\Controller' && $method->name != 'getIndex') {
                    if (substr($method->name, 0, 3) == 'get') {
                        $method_name = substr($method->name, 3);
                        $slug = array_filter(preg_split('/(?=[A-Z])/', $method_name));
                        $slug = strtolower(implode('-', $slug));
                        $slug = ($slug == 'index') ? '' : $slug;
                        Route::get($prefix.$slug.$wildcards, ['uses' => $controller.'@'.$method->name, 'as' => $controller.'Get'.$method_name]);
                    } elseif (substr($method->name, 0, 4) == 'post') {
                        $method_name = substr($method->name, 4);
                        $slug = array_filter(preg_split('/(?=[A-Z])/', $method_name));
                        Route::post($prefix.strtolower(implode('-', $slug)).$wildcards, [
                            'uses' => $controller.'@'.$method->name,
                            'as' => $controller.'Post'.$method_name,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {

        }
    }
}
