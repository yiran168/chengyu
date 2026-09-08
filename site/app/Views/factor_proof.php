<?php
field('current_password','Current password','','password',['required'=>'required','autocomplete'=>'current-password']);
if(!isset($factorRequired) || $factorRequired) {field('factor_code','Authenticator or recovery code','','text',['required'=>'required','autocomplete'=>'one-time-code','maxlength'=>'32']);}
