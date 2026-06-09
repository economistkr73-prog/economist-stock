<?

require "./env/cnt.inc";

$mode = $_REQUEST["mode"];

#변수정의
$cur_php = basename($_SERVER['PHP_SELF']);
$admin_info=($_COOKIE['opt']);
#print_r($admin_info);


if($admin_info['usr_level']!=1) { 
							echo "	  <meta http-equiv=\"refresh\" content=\"0;url=lo.php?url=$cur_php?mode=ord\"> ";
 exit;
}


if($mode=='view')              { m_view ($connect); }
elseif($mode=='ord')              { m_ord ($connect); }

else  {  

 m_ord($connect);
 exit;
#	  echo "	  <meta http-equiv=\"refresh\" content=\"0;url=x.php?mode=view\"> ";
	
	echo "<script language=\"javascript\">
    			alert(\" Version : $ver 모드가 없습니다. \");
    		</script>    			
    			";			
		}

############################################
function m_view($connect) {
###########################################
global $cur_php;
global $admin_info;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');


get_Permit($admin_info,"$cur_php?mode=view");

 $hgt_num=1;
 $wgt_num=4;

if($GR_Vals['mode_two']=='update') {

$today = date("Y-m-d");

  $qry="select max(ord_no) from tbl_mh";		
  $result=mysqli_query($connect, $qry); 
  $get_max_ord_no = mysqli_fetch_array($result);
  $max_ord_no=$get_max_ord_no[0]+1;


if($GR_Vals['no']) $qry_up="update  `tbl_mh`   set  tit='".$GR_Vals['tit']."',last_no='".$GR_Vals['last_no']."',uDate='".$today."',url_dir='".$GR_Vals['url_dir']."' where no='".$GR_Vals['no']."'";  
else $qry_up="insert into  `tbl_mh`   set  tit='".$GR_Vals['tit']."', wb='".$GR_Vals['wb']."',  url_no='".$GR_Vals['url_no']."', ord_no='".$max_ord_no."'";  

#echo $qry_up;

$result_up=mysqli_query($connect,$qry_up); 


//;
$frc_id=$GR_Vals['rn'];

//echo $frc_id;

echo "<body  onload='javascript:self.close();'>";
 
}
elseif($GR_Vals['mode_two']=='url_no') {
# url_no 전체 업데이트
 $qry_url_no_up="update  `tbl_mh`   set   url_no='".$GR_Vals['url_no']."' ";  
 $result_url_no_up=mysqli_query($connect,$qry_url_no_up); 

#echo  $qry_url_no_up;
}

    
		$query_mh="SELECT  * from `tbl_mh`  order by ord_no ";
		$result_mh=mysqli_query($connect,$query_mh); 
		

   $tbl_wth=(100/($wgt_num+1));


	$tr_type_array=array("웹툰","웹소설","일본코믹스");
    $tr_http_array=array("newtoki","booktoki","manatoki");
    $tr_dot_array=array("com","com","net");

	$tr_add_num_array=array(0,0,0);

    $tr_url_array=array("webtoon","novel","comic");



	foreach($result_mh as $m_no => $m_value){



	  $base_svr_num=$tr_add_num_array[$m_value['wb']]+$m_value['url_no'];

    	$base_url[]="https://".$tr_http_array[$m_value['wb']].$base_svr_num.".".$tr_dot_array[$m_value['wb']]."/".$tr_url_array[$m_value['wb']]."/".$m_value['url_dir']."";
		$mh_value[]=$m_value;	

		$mode_no=$m_no%$wgt_num;
				
		if(empty($mode_no)) {
			  $ll++;
			$lno['start_no'][$ll]=$m_no;
			$lno['end_no'][$ll]=$m_no+$wgt_num-1;
			$lno['tot'][$ll]=$ll;
		}

	}




if(empty($GR_Vals['lno'])) $GR_Vals['lno']=1;




	for($tt=0;$tt<count($tr_type_array);$tt++) {
												 if($tt==0) $chk_str="checked";
												 else $chk_str="";
												 $tr_type_str.="<input type=radio name='wb' value='".$tt."' $chk_str>".$tr_type_array[$tt]."";
		}


	$tr_text_input= "<table border=0>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>	
														   <input type=\"hidden\" name=\"mode\" value=\"view\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update\">												
														<tr height='30px;' style='vertical-align:top;'><td colspan=4>

														신규등록
														$tr_type_str   													   
														<input type=\"text\" name=\"tit\" value=\"타이틀\" class=form_nc $auto_clear_tag >
														<input type=\"text\" name=\"url_dir\" value=\"no\" class=form_nc $auto_clear_tag >
														<input type=\"hidden\" name=\"url_no\" value=\"".$mh_value[0]['url_no']."\">
														<input type=\"hidden\" name=\"lno\" value=\"".$GR_Vals['lno']."\">
														<input type=submit  value='등록' style='width:40px;height:30px;cursor:hand;'>
														</td></form>
																												
														<td> 번호증가(<a onclick='up_url_no(".$mh_value[0]['url_no'].");' style='cursor:hand;'>".$mh_value[0]['url_no'].")</tD>
														<Td><a href='$cur_php?mode=ord'>순서 정렬</a></td>
														</tr>												 																									

								</table>
						";



$start_no=$lno['start_no'][$GR_Vals['lno']];
$end_no=$lno['end_no'][$GR_Vals['lno']];

foreach($lno['tot'] as $l => $l_value) {

$start_t_no=$lno['start_no'][$l];
$end_t_no=$lno['end_no'][$l];

$lno_tit_list="<font style='font-size:15px;'>";
	  for($ln=$start_t_no;$ln<=$end_t_no;$ln++) 	$lno_tit_list.=$mh_value[$ln]['tit']." &nbsp;";
$lno_tit_list.="</font>";

	 if($GR_Vals['lno']==$l_value) $lno_list.="<font style='color:red;'> $l_value ".$lno_tit_list."</font> &nbsp;";    
	 else $lno_list.=" <a href='$cur_php?lno=".$l_value."'>$l_value</a> ".$lno_tit_list." &nbsp;";

}


echo "<html><body>";

echo $style_css;

echo "<title>MH</title>";


echo "
			<script type=\"text/javascript\">

			   function up_url_no(url_no) {

				nxt_no=url_no+1;


                 nxt_no= prompt(nxt_no);

								    var url ='$cur_php?mode=view&mode_two=url_no&url_no='+nxt_no	;

								 location.href=url;

				}




		        function      pop_submit(v) {


									 if(0) {																																	 
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}



																																			var popupX = (window.screen.width / 2) ;
																																			var popupY= (window.screen.height / 2) - (1000 / 2);
																																			 
																																			 var size ='width=1,height=1,left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																			
																																			 var n=open('','pop_submits',size); 
																														

																																					v.target = 'pop_submits';
																																					v.action = \"mh.php\" ;																																					
																																					v.submit() ;																																		
																									
																																	} // end of pop_submit ::: 


			</script>
";




echo "<table height=100% width=100% border=1>";

 echo "<tr height=20px;><Td style='font-size:30px;' colspan=".($wgt_num-1).">".$lno_list."</td><td>".$tr_text_input."</td></tr>";

		 echo "<tr valign=top>";		


for($rr=0;$rr<$hgt_num;$rr++) {

            for($ii=$start_no;$ii<=$end_no;$ii++) {	
				


				 $mode_no=$ii%($wgt_num);
				 $rn=$ii;


				               #   if($mh_value[$rn]['wb']) $chk_str="checked"; else $chk_str="";

								  $form_name="myform_".$ii;


				 $input_form="
						         <table border=0>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=$form_name>	
														   <input type=\"hidden\" name=\"mode\" value=\"view\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update\">												
														  <input type=\"hidden\" name=\"lno\" value=\"".$GR_Vals['lno']."\">												
														  <input type=\"hidden\" name=\"no\" value=\"".$mh_value[$rn]['no']."\">												
														  <input type=\"hidden\" name=\"rn\" value=\"".$rn."\">												
														<tr height='30px;' style='vertical-align:top;'><td colspan=4>
														<img src='../img/micon2.gif'> 
														 ".$tr_type_array[$mh_value[$rn]['wb']]."
														<input type=\"text\" name=\"tit\" value=\"".$mh_value[$rn]['tit']."\" style='width:100px;' class=form_nc>
														<input type=\"text\" name=\"url_dir\" value=\"".$mh_value[$rn]['url_dir']."\" style='width:50px;' class=form_nc>

														</td>
														<td style='font-size:14px;'>마지막 조회 (".$mh_value[$rn]['uDate'].") <input type=\"text\" name=\"last_no\" value=\"".$mh_value[$rn]['last_no']."\" style='width:55px;font-size:20px;font-weight:bold;text-align:right;' class=form_nc $auto_clear_tag>화
																												<input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"pop_submit(document.".$form_name.",$rn)\">			
														</td></tr>
														</form>
								</table>";

					echo "		
					     <td valign=top width=".$tbl_wth."%>	
								   								   $input_form									
									<iframe src='".$base_url[$rn]."' id='".$rn."' \" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:95%;\" name='".$rn."' ></iframe>
					          			</td>";
								
                 if($mode_no==0 and $ii>$start_no) echo  "</tr><tr>";


			}
			
										
echo "			</tr>				";


}




			echo "</table>";


										echo "</body></html>";



 ################### end of  x_view #######################
}
################### end of  x_view #######################



############################################
function m_ord($connect) {
###########################################
global $cur_php;
global $admin_info;

require "./env/e.fnc";
require "./env/inf.fnc";

$test_on=0;

$GR_Vals=Get_Vals('mode');

$today = date("Y-m-d");

	$query_mh_no="SELECT  url_no,max(ord_no) as max_ord_no from `tbl_mh` limit 0,1 ";
	$result_mh_no=mysqli_query($connect,$query_mh_no); 
		
     $get_url_no = mysqli_fetch_array($result_mh_no);

get_Permit($admin_info,"$cur_php?mode=view");


if($GR_Vals['mode_two']=='ord_up') {
                 
				 $ord_no=$GR_Vals['ord_no'];
				 $old_ord_no=$ord_no-$GR_Vals['plus'];

				$query_ord_no="update  `tbl_mh`   set  ord_no=ord_no+1 where ord_no>='".$old_ord_no."' and ord_no<='".$ord_no."' ";
				if($test_on)print_r($query_ord_no);
                else  $result=mysqli_query($connect,$query_ord_no); 

				$query_ord_no="update  `tbl_mh`   set  ord_no='".$old_ord_no."' where no='".$GR_Vals['no']."' ";
				if($test_on)print_r($query_ord_no);
                else  $result=mysqli_query($connect,$query_ord_no); 

}

elseif($GR_Vals['mode_two']=='insert') {

 $max_ord_no=$get_url_no['max_ord_no']+1;

  $qry_up="insert into  `tbl_mh`   set  tit='".$GR_Vals['tit']."', wb='".$GR_Vals['wb']."',  url_dir='".$GR_Vals['url_dir']."',  url_no='".$get_url_no['url_no']."',ord_no='$max_ord_no'  ";  

				if($test_on)print_r($qry_up);
                else  $result=mysqli_query($connect,$qry_up); 

}



elseif($GR_Vals['mode_two']=='last_no') {

$query_ord_no="update  `tbl_mh`   set  last_no='".$GR_Vals['last_no']."' ,uDate='".$today."' where no='".$GR_Vals['no']."' ";
				if($test_on)print_r($query_ord_no);
                else  $result=mysqli_query($connect,$query_ord_no); 


}


elseif($GR_Vals['mode_two']=='kg') {

$query_kg="update  `tbl_mh`   set  kg='".$GR_Vals['last_no']."' where no='".$GR_Vals['no']."' ";
				if($test_on)print_r($query_kg);
                else  $result=mysqli_query($connect,$query_kg); 
}


elseif($GR_Vals['mode_two']=='del') {

  $qry_del="delete  from `tbl_mh`  where no='".$GR_Vals['no']."'";  

   $qry_ord="update `tbl_mh`  set ord_no=ord_no-1 where ord_no>".$GR_Vals['ord_no']."";  

				if($test_on) {print_r($qry_del);
										print_r($qry_ord);
										}
                else { 
					$result_del=mysqli_query($connect,$qry_del);  
					$result_ord=mysqli_query($connect,$qry_ord);  
				}

}

elseif($GR_Vals['mode_two']=='url_no') {
# url_no 전체 업데이트

  
 $qry_url_no_up="update  `tbl_mh`   set   url_no=url_no+".$GR_Vals['no_add'];  
 $result_url_no_up=mysqli_query($connect,$qry_url_no_up); 

#echo  $qry_url_no_up;
}

elseif($GR_Vals['mode_two']=='zero') {
# url_no 전체 업데이트

 $qry_url_no_up="update  `tbl_mh`   set   uDate='".$today."' ";  
 $result_url_no_up=mysqli_query($connect,$qry_url_no_up); 

}




if($GR_Vals['mode_two']) {

   if(!$test_on) echo "<body onload=location.href='".$cur_php."?mode=ord'>";     
exit;
}




#if($test_on) { exit; }


if($GR_Vals['if_open']=='on') 	   { setcookie('opt[if_open]',1,time()+12800,'/');  $admin_info['if_open']=1; }
elseif($GR_Vals['if_open']=='off')  { setcookie('opt[if_open]',1,time()-3600,'/'); $admin_info['if_open']=0; }

if($admin_info['if_open']) $if_open_tag="<img src='../img/check_on.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=ord&if_open=off'\" style='cursor:hand;'> ";
else  $if_open_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=ord&if_open=on'\" style='cursor:hand;'> ";


	$query_mh="SELECT  * from `tbl_mh`  order by ord_no ";
	$result_mh=mysqli_query($connect,$query_mh); 

	$tr_type_array=array("웹툰","웹소설","일본코믹스");
    $tr_http_array=array("newtoki","booktoki","manatoki");
    $tr_dot_array=array("com","com","net");

	$tr_add_num_array=array(0,0,0);

    $tr_url_array=array("webtoon","novel","comic");


	for($tt=0;$tt<count($tr_type_array);$tt++) {
												 if($tt==0) $chk_str="checked";
												 else $chk_str="";
												 $tr_type_str.="<input type=radio name='wb' value='".$tt."' $chk_str>".$tr_type_array[$tt]."";
		}

	$tr_text_input= "<table border=0>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>	
														   <input type=\"hidden\" name=\"mode\" value=\"ord\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"insert\">												
														<tr height='30px;' style='vertical-align:top;'><td colspan=4>														
														$tr_type_str  
														</td>
														<td> url_No :: <a href='$cur_php?mode=ord&mode_two=url_no&no_add=-1'>(-)</a>".$get_url_no['url_no']." <a href='$cur_php?mode=ord&mode_two=url_no&no_add=1'>(+)</a></td>
														<td> &nbsp; <a href='$cur_php?mode=ord&mode_two=zero'>일자초기화</td>
														</tr>
															<tr height='30px;' style='vertical-align:top;'><td colspan=5>
														<input type=\"text\" name=\"tit\" value=\"타이틀\" class=form_nc $auto_clear_tag >
														<input type=\"text\" name=\"url_dir\" value=\"no\" class=form_nc $auto_clear_tag >
														<input type=submit  value='등록' style='width:40px;height:30px;cursor:hand;'>
													</td></form>	
													

														</tr>												 																									

								</table>
						";

echo "<html><body>";

echo $style_css;

echo "<title>MH</title>";


echo "
			<script type=\"text/javascript\">



		        function      pop_submit(no,last_no,opt) {

				


									 if(0) {																																	 
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}


                               if(opt==\"kg\") {

								

									if(last_no==0) last_no=1;
									else last_no=0;

									var url ='$cur_php?mode=ord&mode_two=kg&no='+no+'&last_no='+last_no;

									//alert(url);
									
							}

							else {
                                   get_last_no= prompt('마지막번호',last_no+1);
                         		   var url ='$cur_php?mode=ord&mode_two=last_no&no='+no+'&last_no='+get_last_no;
									}



									location.href=url;						


																																			var popupX = (window.screen.width / 2) ;
																																			var popupY= (window.screen.height / 2) - (1000 / 2);
																																			 
																																			 var size ='width=100,height=100,left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																		
																																		//	 var n=open(url,'pop_submits',size); 																																					
									} // end of pop_submit ::: 






			</script>
";



echo "<table height=100% width=1650px; border=0>";

echo "<tr valign=top><td>";

echo "<table width=800px; border=0 style='font-size:17px;' valign=top>";
						 echo "<Tr align=center><td  colspan=10>".$tr_text_input."</td></tR>";

						 echo "<Tr align=center><td width=80px; colspan=2>순서</td><td>경과일</td><td width=100px;>장르</td><td></td><td width=200px;>타이틀 <a href='".$cur_php."'>보기</a></td><td>프레임보기 $if_open_tag</td></tR>";

							foreach($result_mh as $m_no => $m_value){

								$view_url="https://".$tr_http_array[$m_value['wb']].$m_value['url_no'].".".$tr_dot_array[$m_value['wb']]."/".$tr_url_array[$m_value['wb']]."/".$m_value['url_dir']."";

								$del_tags="<img src='../img/ic/12-em-cross.png' onclick=\"javascript:if(!confirm('삭제하시겠습니까?')) return; location.href='$cur_php?mode=ord&mode_two=del&no=".$m_value['no']."&ord_no=".$m_value['ord_no']."'\" style='cursor:hand;'>";
								
								$ord_up="<img src='../img/u.gif'><a href='$cur_php?mode=ord&mode_two=ord_up&ord_no=".$m_value['ord_no']."&no=".$m_value['no']."&plus=1'>+1</a> &nbsp; <img src='../img/u.gif'><a href='$cur_php?mode=ord&mode_two=ord_up&ord_no=".$m_value['ord_no']."&no=".$m_value['no']."&plus=5'>+5</a>";

								if($m_value['ord_no']==1) $ord_up="";

								$interval = date_diff(date_create($today),date_create($m_value['uDate']));

								if($m_value['kg']==1) $interval=0;

								$gap_days= preg_replace('/0/','$2',$interval->days);
								 $gap_days_tag="<a onclick='pop_submit(".$m_value['no'].",".$m_value['last_no']."-1,0);' style='cursor:hand;'>".$gap_days;
								
								if($gap_days>7) $bg_color_style="style='background-color:#EFF2FB;'";

								else { 
									         if($m_value['kg']==1)  { $bg_color_style="style='background-color:#CEECF5;font-size:11px;'";  

											  $gap_days_tag="KG";
											 
											 }
												 else $bg_color_style="style='font-size:17px;'";  
											 
											 }
									
							   echo "
									   <tr $bg_color_style height=50px;>
											   <td align=center>".$m_value['ord_no']."</td>
											   <td>$ord_up</td>
											   <td align=center>".$gap_days_tag."</a></td>
											   <td align=center><a onclick='pop_submit(".$m_value['no'].",".$m_value['kg'].",\"kg\");' style='cursor:hand;'>".$tr_type_array[$m_value['wb']]."</td>
											   <td><a onclick='pop_submit(".$m_value['no'].",".$m_value['last_no'].",0);' style='cursor:hand;'>".$m_value['last_no']."</td>
											   <td colspan=2><a href='".$view_url."' target='_blank'>".$m_value['tit']."</td>
											   <td>$del_tags</td>
											   
									  </tr>";

							 echo "<tr>$dot_line</tr>";
							}

	echo "</table></td>";


if($admin_info['if_open']) {  $base_url="https://newtoki".$get_url_no['url_no'].".com/"; }

	echo "<Td width=1000px;><iframe src='".$base_url."' id='if' \" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:95%;\" name='if' ></iframe></td></tr>";








			echo "</table>";


										echo "</body></html>";



 ################### end of  m_ord #######################
}
################### end of  m_ord #######################




?>

