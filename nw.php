<?php
require "./env/cnt.inc";
require_once "./env/auth_fnc.php";

// 로그인 체크 함수 실행 (비로그인자라면 여기서 실행이 멈추고 login.php로 튕겨나감)
require_login();


# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );

ini_set("allow_url_fopen",1);


#변수정의
$cur_php = basename($_SERVER['PHP_SELF']);
$admin_info=($_COOKIE['opt']);

#print_r($_COOKIE);

#print_r($admin_info);


#변수정의
$mode = $_REQUEST["mode"];

if($mode=='nw_write')               nw_write($connect); 
elseif($mode=='nw_update')      nw_Update ($connect);
elseif($mode=='nw_list')      nw_list ($connect);

elseif($mode=='nw_view')      nw_view ($connect);

elseif($mode=='renter_write')   renter_write($connect); 

elseif($mode=='renter_fee_view')   renter_fee_view($connect); 

else   nw_list ($connect);

############################################
function nw_write($connect) {
###########################################
global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');





echo"<html><body width=100%>";




echo $style_css;

echo  "   						   <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' border=0> 

																										<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																										<input type='hidden'  name=mode  value='nw_update'>			
																										<tr><td colspan=4><input type=submit value='등록' style='width:50px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;'>																										
																										</td></tr>

																									
																									
																										<tr>
																											<td  align=right>
																											회사명
																											</td>
																											<td>
																											<input type='text' name='nw_name' id='nw_name' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																										<tr>
																											<td align=right>
																											사업자번호
																											</td>
																											<td>
																											<input type='text' name='nw_id' id='nw_id' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																									  <tr>
																											<td align=right>
																											주소
																											</td>
																											<td>
																											<input type='text' name='nw_addr' id='nw_addr' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>
																								

																									  <tr>
																											<td align=right>
																											업종/업태
																											</td>
																											<td>
																											<input type='text' name='nw_upjong' id='nw_upjong' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																									  <tr>
																											<td align=right>
																											용도
																											</td>
																											<td>
																											<input type='text' name='nw_use' id='nw_use' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																										<tr>
																											<td align=right>
																											토지면적
																											</td>
																											<td>
																											<input type='text' name='nw_real_size' id='nw_size' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																											<tr>
																											<td align=right>
																											연면적
																											</td>
																											<td>
																											<input type='text' name='nw_tot_size' id='nw_tot_size' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>
																			
																											
																											</form></td></tr></table>

";




echo "</body></html>";


 ################### end of  nw_write #######################
}
################### end of  nw_write #######################


############################################
function nw_Update ($connect) {
###########################################
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');
$G_No=$GR_Vals['no'];

$test_on=0;

 $skip_Array=array('mode');
 $qry_nw= make_qry($GR_Vals,$skip_Array,0);

  $qry_up="insert into  `nw_name` set ".$qry_nw."    ";

						if($test_on) print_r($qry_up); 
						else $result=mysqli_query($connect,$qry_up); 


 ################### end of  nw_Update #######################
}
################### end of  nw_Update #######################


############################################
function nw_list($connect) {
###########################################
global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');


                                  	$qry_nw="SELECT * from `nw_name`" ;
                                    $result_nw=mysqli_query($connect,$qry_nw); 	




echo"<html><body width=100%>";



echo $style_css;


echo  "  <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' border=0> 
   <tr style='background-color:yellow;'><td>회사명</td><td>사업자번호</td><td>주소</td><td>업종</td><td>용도</td><td>토지면적</td><td>연면적</td><td>상세</td></tr>

";

   $p_chg_rate=3.305785;

	foreach($result_nw as $c_no => $c_value){																																			

     $p_real=round($c_value['nw_real_size']/$p_chg_rate,2);
     $p_tot=round($c_value['nw_tot_size']/$p_chg_rate,2);
 

 echo "<tr><td style='font-size:20px;font-weight:bold;'>".$c_value['nw_name']."</td><td>".$c_value['nw_id']."</td><td>".$c_value['nw_addr']."</td><td>".$c_value['nw_upjong']."</td><td>".$c_value['nw_use']."</td><td>".$c_value['nw_real_size']."㎡  ($p_real 평)</td><td>".$c_value['nw_tot_size']."㎡ ($p_tot 평) </td><tD><a href='$cur_php?mode=nw_view&nw_no=".$c_value['nw_no']."'><img src='../img/bul59.gif'></a></td></tr>";


	}


echo "</table>";

echo "</body></html>";


 ################### end of  nw_list #######################
}
################### end of  nw_list #######################



############################################
function nw_view($connect) {
###########################################
global $cur_php;
global $tbl_width;
global $admin_info;


require "./env/e.fnc";
require "./env/inf.fnc";



$GR_Vals=Get_Vals('mode');

                                  	$qry_nw="SELECT * from `nw_name` where nw_no='".$GR_Vals['nw_no']."'"  ;
                                    $result_nw=mysqli_query($connect,$qry_nw); 	
									$c_value=mysqli_fetch_array($result_nw);


   $today = date("Y-m-d");


if($GR_Vals['mode_two']=="ins") {

 $skip_Array=array('mode_two','no');
 $qry_signal= make_qry($GR_Vals,$skip_Array,"skip_yes");

 $qry_room="insert  into `nw_room` set   ".$qry_signal."  ";
 $result=mysqli_query($connect,$qry_room); 

}



$rent_type_array=array(0,"전세","반전세","월세");

echo"<html><body width=100%>";


echo $style_css;
echo ("
													
														   
														   <script type=\"text/javascript\">

																			    function      nw_Confirm(rent_no,opt) {		


																					  if(opt==1) {  
																						  	   go_to_url_tags  ='$cur_php?mode=renter_write&mode_two=contract_finish&rent_no='+rent_no+'';																						

																							   cmt='계약 종료 하시겠습니까?';																							   

																								}

																					  if(opt==11) {  
																						  	   go_to_url_tags  ='$cur_php?mode=renter_write&mode_two=contract_finish&rent_no='+rent_no+'';																						

																							   cmt='계약을 중도해지 하시겠습니까?';																							   

																								}

																					  else if(opt==2) {   // 만기연장
																						  	   go_to_url_tags  ='$cur_php?mode=renter_write&mode_two=contract_finish&go_on=1&rent_no='+rent_no+'';																	
   																							   cmt='만기 연장 하시겠습니까?';
																								}

																					  else if(opt==3) {   // 묵시적갱신
																						  	   go_to_url_tags  ='$cur_php?mode=renter_write&mode_two=contract_continue&rent_no='+rent_no+'';																	
   																							   cmt='묵시적 갱신을 하시겠습니까?';
																								}

																					  else if(opt==4) {   // 월세 리스트
																						  	   go_to_url_tags  ='$cur_php?mode=renter_fee_view&rent_no='+rent_no+'';																	
   																							   cmt='월세/관리비 납부 현황를 보시겠습니까?';
																								}

																
																                             	  if (confirm(cmt)) {																											

                                                                                                                                           window.document.location.href=go_to_url_tags;
																						
																												
																											} 

																																																																	
																							}

																							
			</script>



											");



echo  "
		 <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' border=0> 
			<tr style='background-color:yellow;'><td>회사명</td><td>사업자번호</td><td>주소</td><td>업종</td><td>용도</td><td>토지면적</td><td>연면적</td></tr>
	";

   $p_chg_rate=3.305785;
   $p_real=round($c_value['nw_real_size']/$p_chg_rate,2);
   $p_tot=round($c_value['nw_tot_size']/$p_chg_rate,2);

 echo "<tr><td style='font-size:20px;font-weight:bold;'><a href='$cur_php?mode=nw_list'>".$c_value['nw_name']."</td><td>".$c_value['nw_id']."</td><td>".$c_value['nw_addr']."</td><td>".$c_value['nw_upjong']."</td><td>".$c_value['nw_use']."</td><td>".$c_value['nw_real_size']."㎡  ($p_real 평)</td><td>".$c_value['nw_tot_size']."㎡ ($p_tot 평) </td></tr>";

  $ins_form="<table style='font-size:15px;'><tr><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																										<input type='hidden'  name=mode  value='nw_view'>			
																										<input type='hidden'  name=mode_two  value='ins'>
																										<input type='hidden'  name=nw_no  value='".$GR_Vals['nw_no']."'>
																										<td>임대호수</td><td><input type='text' name='room_id' id='room_id' value='' $auto_clear_tag size='4'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;font-size:17px;'>호
																										</td><td>
																										임대면적</td><td><input type='text' name='room_size' id='room_size' value='' $auto_clear_tag size='5'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;font-size:17px;'>㎡
																										</td><td>
																										근저당(은행대출)</td><td><input type='text' name='bank_loan' id='room_size' value='' $auto_clear_tag size='9'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;font-size:17px;'>원</td>
																										<td><input type=submit value='등록' style='width:50px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;'></td>
																										</tr></table></form>
																										";

if($c_value['nw_up_close']) $ins_form="";

echo "<tr><tD></tD><td colspan=10>".$ins_form."</td></tr>";

echo "<tr>$dot_line</tr>";

echo "<tr><td colspan=10>";

 ## 호실 리스트

                                  	$qry_room="SELECT * from `nw_room` where nw_no='".$GR_Vals['nw_no']."'"   ;
                                    $result_room=mysqli_query($connect,$qry_room); 	

    echo "<table>";
      echo "<tr align=center><td>호실</tD><td>임대면적(평수)</td><td>근저당</td><td>세입자이름</td><td>렌트타입<br>(<img src='../img/check_on.gif'>전세권)</td><td>보증금</td><td width=50px;>월세</td><td>관리비</td><td >월세납부일<br><font style='font-size:12px;color:blue;'>(마지막납부일)</td><td>계약시작<br><font style='font-size:12px;color:blue;'>(묵시적갱신)</td><td>계약만료<br><font style='font-size:12px;color:blue;'>(중도해지)</td><td>공인중개사</td><td>특약</td><td colspan=3>계약갱신(최초계약일)</td></tr>";	           

echo "<tr>$dot_line</tr>";

	foreach($result_room as $r_no => $r_value){			
		
		      $r_real=round($r_value['room_size']/$p_chg_rate,2);
  
			  if($r_value['bank_loan']) $bank_loan_val=deco_txt($r_value['bank_loan']/1000000,3,0)."<font style='font-size:12px;'> 백만";
			  else $bank_loan_val="";

			                        $qry_renter="SELECT * from `nw_renter` where room_no='".$r_value['room_no']."' and prg_on>0"  ;
                                    $result_renter=mysqli_query($connect,$qry_renter); 	
									$renter_value=mysqli_fetch_array($result_renter);

									$tot_renter_deposit+=$renter_value['renter_deposit'];
									$tot_rent_fee+=$renter_value['rent_fee'];
									$tot_rent_cost+=$renter_value['rent_cost'];

									
									if($renter_value['rent_no']) {

									$modify_tag="<a href='$cur_php?mode=renter_write&nw_no=".$renter_value['nw_no']."&room_no=".$renter_value['room_no']."&rent_no=".$renter_value['rent_no']."'>";

									if($renter_value['prg_on']>1) { $bg_color="background:red;color:white;";  $prg_on_cmt="再계약";
  									$renter_prg_on="<font style='color:blue;font-size:12px;'>".$renter_value['renter_contract_first_day']."~";										
									
									}

									else { $bg_color="color:blue"; $prg_on_cmt="연장"; 
									$renter_prg_on="";
									}

									  #묵시적 갱신 여부
									 	if($renter_value['prg_on']==3) { 
											$contact_continue=1;   $prg_on_cmt="묵시적갱신中";  $bg_color="background:black;color:white;";  $contact_continue_num=2; 
											$renter_prg_on="<font style='color:blue;font-size:12px;'>".$renter_value['renter_contract_final_day']."~";										
											
											}
										else { $contact_continue=0;  $contact_continue_num=2;}


									# 만기가 지났으면..

									if($renter_value['renter_contract_final_day']<$today and $contact_continue==0) { 

										$contract_final_tag="<input type=button value=\"만료\"  style='width:40px;cursor:hand;border-radius: 7px;border:dashed 1px orange;background:black;color:white;'  onclick='nw_Confirm(".$renter_value['rent_no'].",1)'>";
									
									} else $contract_final_tag="";

					
									$contract_up="<td>".$contract_final_tag."</td>
															  <td><input type=button value=\"".$prg_on_cmt."\"  style='width:90px;cursor:hand;border-radius: 7px;border:dashed 1px orange;".$bg_color."'  onclick='nw_Confirm(".$renter_value['rent_no'].",".$contact_continue_num.")'> $renter_prg_on</td>";

									}

									else {

										$modify_tag="";
										$contract_up="<td colspan=2></td>";
									
									}


									if($renter_value['contract_special']) $contract_special_tag="<img src='../img/check_on.gif'>"; else $contract_special_tag="";


									$contract_day=calender_str(3,13,$renter_value['renter_contract_day']);
									$contract_final_day=calender_str(3,13,$renter_value['renter_contract_final_day']);

								#	print_r($contract_day);
                               #전세권
									if($renter_value['deposit_law']) $deposit_law_tag="<img src='../img/check_on.gif'><font style='color:red;font-weight:bold;'>"; else $deposit_law_tag="";

									# 월세 납부일 가져오기

									 	$qry_income="SELECT * from `nw_renter_fee_history` where rent_no='".$renter_value['rent_no']."' order by renter_income_day desc limit 0,1"  ;
                                        $result_income=mysqli_query($connect,$qry_income); 	
										$income_value=mysqli_fetch_array($result_income);
										
										if($income_value) {

											$income_day=calender_str(3,13,$income_value['renter_income_day']);
											$income_tags=$income_day['unix_str'];

										} else $income_tags="-";



			       if($renter_value['renter_name']) $renter_info="<td>".$renter_value['renter_name']."</td><td>".$deposit_law_tag." ".$rent_type_array[$renter_value['rent_type']]."</td><td align=right>".deco_txt($renter_value['renter_deposit']/1000000,3,0)."<font style='font-size:12px;'> 백만</td><td align=right>".deco_txt($renter_value['rent_fee']/10000,3,0)."만</td><td>".deco_txt($renter_value['rent_cost']/10000,3,0)."만</td>
				   <td  style='font-size:13px;cursor:hand;'><a href='$cur_php?mode=renter_fee_view&rent_no=".$renter_value['rent_no']."'>(".$renter_value['rent_fee_day'].")
				    $income_tags</td>
				   <td style='font-size:13px;cursor:hand;' onclick='nw_Confirm(".$renter_value['rent_no'].",3)'>".$contract_day['unix_str']."</td>
				   
				   <td style='font-size:13px;cursor:hand;' onclick='nw_Confirm(".$renter_value['rent_no'].",11)' >".$contract_final_day['unix_str']."</td><td>".$renter_value['agent_office']."</td><td>".$contract_special_tag."</td>"; 
					   else $renter_info="<td colspan=10><a href='$cur_php?mode=renter_write&nw_no=".$r_value['nw_no']."&room_no=".$r_value['room_no']."'>등록된 내역이 없습니다.(신규 등록)</a></td>";

		      echo "<tr><td>$modify_tag".$r_value['room_id']."</tD><td>$r_real 평 <font style='font-size:12px;'>(".$r_value['room_size']." ㎡)</font></td><td align=right>$bank_loan_val</td>".$renter_info."			  
																											</td>".$contract_up."</tr>";	           

	}

if($admin_info['usr_name']=='ewoong') 	echo "<tr><td colspan=15>(보증금)".deco_txt($tot_renter_deposit/100000000,31,0)."<font style='font-size:12px;'> 억</font> (월세)".deco_txt($tot_rent_fee/10000,3,0)."만 (관리비) ".deco_txt($tot_rent_cost/10000,3,0)." 만원</td></tr>";

	    echo "</table>";

echo "</td></tr>";

echo "</table>";

echo "</body></html>";


 ################### end of  nw_view #######################
}
################### end of  nw_view #######################





############################################
function renter_write($connect) {
###########################################
global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');

$today_ptime=calender_str(1,0,time());
$today_rtime=$today_ptime['unix_str'];

if($GR_Vals['rent_no']) {
	
									$qry_renter="SELECT * from `nw_renter` where rent_no='".$GR_Vals['rent_no']."'"  ;
                                    $result_renter=mysqli_query($connect,$qry_renter); 	
									$renter_value=mysqli_fetch_array($result_renter);

   	   $form_mode_two="update";

	  $from_rent_no="<input type='hidden'  name=rent_no  value='".$GR_Vals['rent_no']."'>";

	  
	   $contract_day=$renter_value['renter_contract_day'];
	   $remainder_day=$renter_value['renter_remainder_day'];
	   $contract_final_day=$renter_value['renter_contract_final_day'];


	   $GR_Vals['nw_no']=$renter_value['nw_no'];
   	   $GR_Vals['room_no']=$renter_value['room_no'];

} else {

	   $form_mode_two="ins";

	   $contract_day=$today_rtime;
	   $remainder_day=$today_rtime;
	   $contract_final_day=$today_rtime;

}



                                  	$qry_nw="SELECT * from `nw_name` where nw_no='".$GR_Vals['nw_no']."'"  ;
                                    $result_nw=mysqli_query($connect,$qry_nw); 	
									$nw_value=mysqli_fetch_array($result_nw);

									$qry_room="SELECT * from `nw_room` where room_no='".$GR_Vals['room_no']."'"  ;
                                    $result_room=mysqli_query($connect,$qry_room); 	
									$room_value=mysqli_fetch_array($result_room);



if($GR_Vals['mode_two']) {

			 $skip_Array=array('mode_two');
			 $qry_renter= make_qry($GR_Vals,$skip_Array,"skip_yes");

							if($GR_Vals['mode_two']=="ins") {							 
							 $qry_renter="insert  into `nw_renter` set   ".$qry_renter."  ";
							 
							}

						elseif($GR_Vals['mode_two']=="update") {

								 $qry_renter="update `nw_renter` set   ".$qry_renter."  where rent_no='".$GR_Vals['rent_no']."'";
						}


						elseif($GR_Vals['mode_two']=="contract_continue") {
								 $qry_renter="update `nw_renter` set   prg_on=3  where rent_no='".$GR_Vals['rent_no']."'";

						}



						elseif($GR_Vals['mode_two']=="contract_finish") {

								 $qry_renter="update `nw_renter` set   prg_on=-1  where rent_no='".$GR_Vals['rent_no']."'";

								  $form_mode_two="ins";
								  $from_rent_no="";

								  if($GR_Vals['go_on']) 	  $from_rent_go_on="<input type='hidden'  name=prg_on  value='2'><input type='hidden'  name=renter_contract_first_day  value='".$renter_value['renter_contract_day']."'>";

						}


		$result=mysqli_query($connect,$qry_renter); 

		if(!$GR_Vals['go_on']) 	{		 Header("Location:$cur_php?mode=nw_view&nw_no=".$GR_Vals['nw_no']."");
		exit;
		}
}


$rent_type_array=array(0,"전세","반전세","월세");
	 $chk_type[$renter_value['rent_type']]="checked";
for($tt=1;$tt<count($rent_type_array);$tt++) {




	 $renter_type_tags.="<input type='radio' name='rent_type' id='rent_type' value='".$tt."' ".$chk_type[$tt].">".$rent_type_array[$tt]."";

}


echo"<html><body width=100%>";


echo ("
													
														   
														   <script type=\"text/javascript\">

		
                                                            function      submit_Confirm(v) {		

																 //  폼으로 넘어온 변수 이름과 값을 확인




cd=v.renter_contract_day.value;
v.renter_contract_day.value=cd.split(' ',1);

cr=v.renter_remainder_day.value;
v.renter_remainder_day.value=cr.split(' ',1);

cf=v.renter_contract_final_day.value;
v.renter_contract_final_day.value=cf.split(' ',1);

 if(document.getElementById('deposit_law').checked) v.deposit_law.value=1;
 else v.deposit_law.value=0;															 	
																	if(v.deposit_law.checked == true) v.deposit_law.value=1;

																																 if(0) {
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}

																																																																																				

																										  if (confirm(\"등록하시겠습니까?\")) {
																											  v.submit();
																												
																											} 

																																																																	
																							}


			</script>
			");


echo $style_css;

echo  "   						   <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' border=0> 


																										<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																										
																										<input type='hidden'  name=mode  value='renter_write'>			
																										$from_rent_no
																										$from_rent_go_on
																										<input type='hidden'  name=mode_two  value='".$form_mode_two."'>			
																										<input type='hidden'  name=nw_no  value='".$GR_Vals['nw_no']."'>
																										<input type='hidden'  name=room_no  value='".$GR_Vals['room_no']."'>
																										
																									<tr><td colspan=10 style='font-size:20px;font-weight:bold;'>																									
																										".$nw_value['nw_name']."

																										(".$room_value['room_id']."호)
																										임차인 정보 등록

																										</td></tr>

																												<tr>
																											<td>계약형태</td>
																											<td colspan=4>".$renter_type_tags."</td>
																										</tr>																									
																									

																									
																										<tr>
																											<td  align=right>
																											이름
																											</td>
																											<td>
																											<input type='text' name='renter_name' id='renter_name' value='".$renter_value['renter_name']."' $auto_clear_tag size='10'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																											<td  align=right>
																											주민번호

																											</td>
																											<td>
																											<input type='text' name='renter_jumin' id='renter_jumin' value='".$renter_value['renter_jumin']."' $auto_clear_tag size='15'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>

																											<td align=right>
																											연락처
																											</td>
																											<td>
																											<input type='text' name='renter_hp' id='renter_hp' value='".$renter_value['renter_hp']."' $auto_clear_tag size='15'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																									


																										<tr>
																											<td  align=right>
																											공동임차인
																											</td>
																											<td>
																											<input type='text' name='renter_name_s' id='renter_name_s' value='".$renter_value['renter_name_s']."' $auto_clear_tag size='10'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																											<td  align=right>
																											주민번호

																											</td>
																											<td>
																											<input type='text' name='renter_jumin_s' id='renter_jumin_s' value='".$renter_value['renter_jumin_s']."' $auto_clear_tag size='15'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>

																											<td align=right>
																											연락처
																											</td>
																											<td>
																											<input type='text' name='renter_hp_s' id='renter_hp' value='".$renter_value['renter_hp_s']."' $auto_clear_tag size='15'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										</tr>

																									
																									  <tr>
																											<td align=right>
																											보증금
																											</td>
																											<td>
																											<input type='text' name='renter_deposit' id='renter_deposit' value='".$renter_value['renter_deposit']."' $auto_clear_tag size='11'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> 원
																											</td>

																											<td colspan=4>
																											<input type='checkbox' name='deposit_law'  id='deposit_law'  ><font color=red>전세권설정
																											</td>
																											
																										</tr>

																									  <tr>
																											<td align=right>
																											계약금
																											</td>
																											<td>
																											<input type='text' name='renter_contract' id='renter_contract' value='".$renter_value['renter_contract']."' $auto_clear_tag size='11'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> 원
																											</td>																																																				
																											<td>
																													계약일
																											</td>
																											<td >
																											<input type='text' name='renter_contract_day'  id='contract_day' value='".$contract_day."' size='14' readonly class=form_nc onclick=\"check_mouse('myform.contract_day','','0');\" style='cursor:hand;text-align:center;'>
																											</td>

																										
																										</tr>

																											  <tr>
																											<td align=right>
																											잔금
																											</td>
																											<td>
																											<input type='text' name='renter_remainder' id='renter_remainder' value='".$renter_value['renter_remainder']."' $auto_clear_tag size='11'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> 원
																											</td>																																																				
																											<td>
																											잔금일
																											</td>
																											<td>
																											<input type='text' name='renter_remainder_day'  id='remainder_day' value='".$remainder_day."' size='14' readonly class=form_nc onclick=\"check_mouse('myform.remainder_day','','0');\" style='cursor:hand;text-align:center;'>
																											</td>

																												<td>
																													~만료일
																											</td>
																											<td >
																											<input type='text' name='renter_contract_final_day'  id='contract_final_day' value='".$contract_final_day."' size='14' readonly class=form_nc onclick=\"check_mouse('myform.contract_final_day','','0');\" style='cursor:hand;text-align:center;'>
																											</td>
																										</tr>
																												  <tr> 
																										
																											<td>차임(월세)</td>
																											<td><input type='text' name='rent_fee' id='rent_fee' value='".$renter_value['rent_fee']."' $auto_clear_tag size='11'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> 원</td>
																											<td>관리비</td>
																											<td><input type='text' name='rent_cost' id='rent_cost' value='".$renter_value['rent_cost']."' $auto_clear_tag size='11'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> 원</td>

																											<td>납부일</td>
																											<td>(매월)<input type='text' name='rent_fee_day' id='rent_fee_day' value='".$renter_value['rent_fee_day']."' $auto_clear_tag size='4'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>일</td>


																										</tr>																								

																											<tr>
																											<td  align=right>
																											공인중개업소
																											</td>
																											<td>
																											<input type='text' name='agent_office' id='agent_office' value='".$renter_value['agent_office']."' $auto_clear_tag size='10'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																											<td  align=right>
																											전화번호
																											</td>
																											<td>
																											<input type='text' name='agent_hp' id='agent_hp' value='".$renter_value['agent_hp']."' $auto_clear_tag size='15'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																											<td align=right>
																											대표자
																											
																											</td>
																											<td>
																											<input type='text' name='agent_ceo' id='agent_ceo' value='".$renter_value['agent_ceo']."' $auto_clear_tag size='15'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											</td>
																										
																										</tr>

																										<tr><td colspan=6><textarea name=contract_special style=\"width:565px; height:200px; overflow-x:hidden; overflow-y:auto;font-size:15px; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;border-radius: 10px;\" class=form_nc>".$renter_value['contract_special']."</textarea></td></tr>

																										<tr><Td colspan=6><input type=button value=\"등록\"  style='width:50px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;'  onclick='submit_Confirm(document.myform)'>
																										$calender_js

																											</form></td>


																											
																											
																											</tr></table>

";




echo "</body></html>";


 ################### end of  renter_write #######################
}
################### end of  renter_write #######################



############################################
function renter_fee_view($connect) {
###########################################
global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');

									$qry_renter="SELECT * from `nw_renter` where rent_no='".$GR_Vals['rent_no']."'"  ;
                                    $result_renter=mysqli_query($connect,$qry_renter); 	
									$renter_value=mysqli_fetch_array($result_renter);
	  
	   $contract_day=$renter_value['renter_contract_day'];
	   $contract_final_day=$renter_value['renter_contract_final_day'];

  $MM=date("m",time());
  $YY=date("Y",time()); 
  $mktime=mktime(0,0,0, $MM, $renter_value['rent_fee_day'], $YY);
$today_ptime=calender_str(1,0,$mktime);
$today_rtime=$today_ptime['unix_str'];

                                  	$qry_nw="SELECT * from `nw_name` where nw_no='".$renter_value['nw_no']."'"  ;
                                    $result_nw=mysqli_query($connect,$qry_nw); 	
									$nw_value=mysqli_fetch_array($result_nw);

									$qry_room="SELECT * from `nw_room` where room_no='".$renter_value['room_no']."'"  ;
                                    $result_room=mysqli_query($connect,$qry_room); 	
									$room_value=mysqli_fetch_array($result_room);

if($GR_Vals['mode_two']) {

			 $skip_Array=array('mode_two');
			 $qry_renter= make_qry($GR_Vals,$skip_Array,"skip_yes");

							if($GR_Vals['mode_two']=="ins") {							 
							 $qry_renter="insert  into `nw_renter_fee_history` set   ".$qry_renter."  ";							 
							}
		$result=mysqli_query($connect,$qry_renter); 

}

$rent_type_array=array(0,"전세","반전세","월세");
$renter_type_tags=" *".$rent_type_array[$renter_value['rent_type']];

if($renter_value['deposit_law']) $deposit_law_tag="<img src='../img/check_on.gif'> <font style='color:red;'> 전세권 설정中 ";

echo"<html><body width=100%>";

echo ("
													
														   
														   <script type=\"text/javascript\">

		
                                                            function      submit_Confirm(v) {		

																 //  폼으로 넘어온 변수 이름과 값을 확인


cd=v.renter_income_day.value;
v.renter_income_day.value=cd.split(' ',1);



										 	
																	

																																 if(0) {
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}																

																										  if (confirm(\"등록하시겠습니까?\")) {
																											  v.submit();
																												
																											} 

																																																																	
																							}


			</script>
			");


echo $style_css;

echo  "   						   <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px; font-size:14px;' border=0 width=500px;> 

																										
																									<tr><td colspan=10 style='font-size:20px;font-weight:bold;background-color:yellow; '>																									
																										".$nw_value['nw_name']."

																										(".$room_value['room_id']."호)
																										 월세/관리비 납부 현황

																										 <a href='$cur_php?mode=nw_view&nw_no=".$renter_value['nw_no']."'>List</a>

																										</td></tr>

																										<tr>$dot_line</tr>

																									
																										<tr>
																											<td  align=right>
																											(이름)
																											</td>
																											<td>
																											".$renter_value['renter_name']."
																											</td>
																										
																											<td align=right>
																											(연락처)
																											</td>
																											<td>
																											".$renter_value['renter_hp']."
																											</td>
																										</tr>

																										<tr>$dot_line</tr>

																																																		
																									
																									  <tr>
																											<td align=right>
																											(보증금)
																											</td>
																											<td>
																											".deco_txt($renter_value['renter_deposit'],3,0)." 원
																											</td>

																											<td colspan=4>
																											$renter_type_tags
																											$deposit_law_tag																											
																											</td>
																											
																										</tr>			

																										<tr>$dot_line</tr>
																											
																										<tr>																																																		
																											<td align=right>
																													(계약일)
																											</td>
																											<td >
																											".$contract_day."
																											</td>

																												<td>
																													(만료일)
																											</td>
																											<td >
																											".$contract_final_day."
																											</td>
																										</tr>

																										<tr>$dot_line</tr>


																												  <tr> 
																										
																											<td align=right>(월세)</td>
																											<td> ".deco_txt($renter_value['rent_fee'],3,0)." 원</td>
																											<td>(관리비)</td>
																											<td> ".deco_txt($renter_value['rent_cost'],3,0)." 원</td>

																											<td>(납부일)</td>
																											<td>(매월)".$renter_value['rent_fee_day']." 일</td>

																										</tr>																								

																										<tr>$dot_line</tr>

																											<tr>
																											<td  align=right>
																											(공인중개업소)
																											</td>
																											<td>
																											".$renter_value['agent_office']."
																											</td>
																											<td  align=right>
																											(전화번호)
																											</td>
																											<td>
																											".$renter_value['agent_hp']."
																											</td>
																											<td align=right>
																											(대표자)																											
																											</td>
																											<td>
																											".$renter_value['agent_ceo']."
																											</td>																										
																										</tr>
																										<tr>$dot_line</tr>																									

																										<tr height=30px;><Td></td></tr>

																										<tr>$dot_line</tr>																									
																									
																										<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>																										
																										<tr><Td colspan=6>
																										
																										<input type='hidden'  name=mode  value='renter_fee_view'>			
																										<input type='hidden'  name=mode_two  value='ins'>			
																										<input type='hidden'  name=rent_no  value='".$GR_Vals['rent_no']."'>
																										(월세) <input type='text' name='rent_fee' id='rent_fee' value='".$renter_value['rent_fee']."'  size='7'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																										(관리비) <input type='text' name='rent_cost' id='rent_cost' value='".$renter_value['rent_cost']."'  size='7'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																										(납부일) 
																										<input type='text' name='renter_income_day'  id='income_day' value='".$today_rtime."' size='14' readonly class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;cursor:hand;' onclick=\"check_mouse('myform.income_day','','0');\" >
																										<input type=button value=\"등록\"  style='width:50px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;'  onclick='submit_Confirm(document.myform)'>
																										$calender_js

																										</form></td>
																										
																										</tr>

";

echo "<tr><td colspan=10>";

echo "<table align=center width=100%><tr style='background-color:yellow;text-align:center;'><td width=100px;>납부일</td><td  width=80px;>월세</td><td  width=80px;>관리비</td></tr>";
echo 	"<tr>$dot_line</tr>";

# 월세 납부 내역

                                  	$qry_income="SELECT * from `nw_renter_fee_history` where rent_no='".$renter_value['rent_no']."' order by renter_income_day desc"  ;
                                    $result_income=mysqli_query($connect,$qry_income); 	

	foreach($result_income as $r_no => $r_value){	

		        $income_unix_time=calender_str(3,13,$r_value['renter_income_day']);

echo "<tr style='text-align:center;'><td>".$income_unix_time['unix_str']."</td><td>".deco_txt($r_value['rent_fee'],3,0)." 원</td><td>".deco_txt($r_value['rent_cost'],3,0)." 원</td></tr>";
echo 	"<tr>$dot_line</tr>";

	}


echo "</table></td></tr>";


echo "</table>";

echo "</body></html>";


 ################### end of  renter_write #######################
}
################### end of  renter_write #######################

?>

