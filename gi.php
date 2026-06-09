<?php
require "./env/cnt.inc";


# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );

ini_set("allow_url_fopen",1);


$mode = $_REQUEST["mode"];

#변수정의
$cur_php = basename($_SERVER['PHP_SELF']);
$admin_info=($_COOKIE['opt']);

#print_r($_COOKIE);
#print_r($admin_info);

if($admin_info['usr_level']==0) { 
							echo "	  <meta http-equiv=\"refresh\" content=\"0;url=lo.php?url=$cur_php\"> ";
 exit;
}

if($mode=='cat')               mng_cat($connect); 
elseif($mode=='bg_year') budget_year($connect);


############################################
function mng_cat($connect) {
###########################################
global $cur_php;
global $admin_info;

require "./env/e.fnc";
require "./env/inf.fnc";


$GR_Vals=Get_Vals('mode');

$test_on=0;



if($GR_Vals['mode_two']=='insert') {

  $qry_up="insert into  `gi_cat_one`   set  tit='".$GR_Vals['tit']."'  ";  
	if($test_on)print_r($qry_up);
         else  $result=mysqli_query($connect,$qry_up); 
}


elseif($GR_Vals['mode_two']=='insert_cat_two') {

  $qry_up="insert into  `gi_cat_two`   set  cat_one='".$GR_Vals['cat_one']."',tit='".$GR_Vals['tit']."'  ";  
	if($test_on)print_r($qry_up);
         else  $result=mysqli_query($connect,$qry_up); 

}



$query_cat="SELECT  * from `gi_cat_one`  order by no ";
$result_cat=mysqli_query($connect,$query_cat); 


if($GR_Vals['cat_one']) {

$cat_two_tags="<table border=0 align=left>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
														   <input type=\"hidden\" name=\"mode\" value=\"cat\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"insert_cat_two\">												
                                                          <input type=\"hidden\" name=\"cat_one\" value=\"".$GR_Vals['cat_one']."\">
														<tr height='30px;' style='vertical-align:top;'><td colspan=4>
														중분류<input type=\"text\" name=\"tit\" value=\"타이틀\" class=form_nc $auto_clear_tag >
														<input type=submit  value='등록' style='width:40px;height:30px;cursor:hand;'>
													</td></form>														
												</tr>	
						";

$query_cat_two="SELECT  * from `gi_cat_two`  where cat_one='".$GR_Vals['cat_one']."' order by no ";
$result_cat_two=mysqli_query($connect,$query_cat_two); 




foreach($result_cat_two as $t_no => $t_value){

		 $cat_two_tags.= "<Tr align=center><td>".$t_value['no']."</td><td align=left><a href='$cur_php?mode=cat&cat_two=".$t_value['no']."'>".$t_value['tit']."</td></tR>";

}

$cat_two_tags.="</table>";

$cat_two_input[$GR_Vals['cat_one']]=$cat_two_tags;

}


	$cat_one_input= "<table border=0>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
														   <input type=\"hidden\" name=\"mode\" value=\"cat\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"insert\">												
														<tr height='30px;' style='vertical-align:top;'><td colspan=4>
														대분류<input type=\"text\" name=\"tit\" value=\"타이틀\" class=form_nc $auto_clear_tag >
														<input type=submit  value='등록' style='width:40px;height:30px;cursor:hand;'>
													</td></form>	
														</tr>		
								</table>
						";

echo "<html><body>";

echo $style_css;

echo "<title>관일산업</title>";

echo "<table border=0>";

echo "<tr><td>";

echo "<table width=550px; border=0 style='font-size:15px;'>";
						 echo "<Tr align=center><td  colspan=10>".$cat_one_input."</td></tR>";

						 echo "<Tr align=center style='background:yellow;'><td width=20px;>순서</td><td>대분류</td></tR>";

							foreach($result_cat as $c_no => $c_value){

								                              if($GR_Vals['cat_one']==$c_value['no']){ 																  
																  $bg_style='style=background:yellow;';
																  $cat_one_tags="";

																}
															  else { $bg_style=''; 
																		$cat_one_tags="<a href='$cur_php?mode=cat&cat_one=".$c_value['no']."'>";
																		}

														 echo "<Tr align=center><td rowspan=2>".$c_value['no']."</td><td align=left $bg_style>".$cat_one_tags.$c_value['tit']."</td></tR>";
														  echo "<Tr align=center><td colspan=2> ".$cat_two_input[$c_value['no']]."</td></tR>";

							}

	echo "</table></td>";




			echo "</table>";


										echo "</body></html>";





echo "</body></html>";

################### End of mng_cat ####################### 
}
################### End of mng_cat ####################### 



############################################
function budget_year($connect) {
###########################################
global $cur_php;
global $admin_info;

require "./env/e.fnc";
require "./env/inf.fnc";


$GR_Vals=Get_Vals('mode');

$test_on=0;


print_r($GR_Vals);

foreach($GR_Vals as $g_no => $g_value){

	echo  $g_no."-".$g_value."<br>";

}


# 대분류를 가지고옴
$query_cat="SELECT  * from `gi_cat_one`  order by no ";
$result_cat=mysqli_query($connect,$query_cat); 


$cat_tags="<table border=1><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
														   <input type=\"hidden\" name=\"mode\" value=\"bg_year\">";


foreach($result_cat as $c_no => $c_value){

# 중분류가져오기

$query_cat_two="SELECT  * from `gi_cat_two`  where cat_one=".$c_value['no']." order by no ";
$result_cat_two=mysqli_query($connect,$query_cat_two); 

   
   
	 $cat_tags.="<tr><td>".$c_value['tit']."</td><td><table>";

	 foreach($result_cat_two as $t_no => $t_value){

		 $cat_tags.="<tr height=35px;><td width=150px;>".$t_value['tit']."</td><td>\\<input type=\"text\" name=\"".$t_value['no']."\" value=\"\" style='font-size:25px;height:35px;' class=form_nc $auto_clear_tag size=15 >원</td></tr>";

	 }
	 
	 $cat_tags.="</table></td></tr>";


#		 $cat_two_tags.= "<Tr align=center><td>".$t_value['no']."</td><td align=left><a href='$cur_php?mode=cat&cat_two=".$t_value['no']."'>".$t_value['tit']."</td></tR>";

}
 
 $cat_tags.="<input type=submit  value='등록' style='width:40px;height:30px;cursor:hand;'>
													</form>";

   $cat_tags.="</table>";


echo "<html><body>";

echo $style_css;

echo "<title>관일산업</title>";

echo "<table border=0>";

echo "<tr><td>";


echo $cat_tags;


	echo "</td>";




			echo "</table>";


										echo "</body></html>";





echo "</body></html>";

################### End of mng_cat ####################### 
}
################### End of mng_cat ####################### 





?>

