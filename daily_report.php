<?php

# DB연결
require "./env/cnt.inc";

# error 표시
 error_reporting( E_ALL& ~E_NOTICE ); # ~E_NOTICE
 ini_set( "display_errors", 1 );

#변수정의
$mode = $_REQUEST["mode"];

if(!$mode) $mode='write';
$cur_php = basename($_SERVER['PHP_SELF']);



#변수정의


if($mode=='write')              { reporT_Write ($connect); }

elseif($mode=='report_lisT' or $mode=='rl')              { reporT_List($connect); }

elseif($mode=='report_reaD')              { reporT_reaD($connect); }


elseif($mode=='if')              { report_iFrame(); }




elseif($mode=='updatE')         {  reporT_update($connect); }





else  {  echo "<script language=\"javascript\">
    			alert(\" Version : $ver \");
    			</script>    			
    			";			
		}

mysqli_close($connect);



############################################
function reporT_write($connect) {
###########################################
require "./env/e.fnc";
require "./env/inf.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$G_No=$GR_Vals['no'];

$default_cts="1) 전일 해외시장 (미국,유럽 등)<br><br><br><br>
2) 당일 시장 마감 ( 지수, 자금동향, 투자자별 매매현황, 아시아시장 )<br><br><br><br>
3) 당일 금융시장 (환율,금리)<br><Br><br><br>
4) 테마 및 급등 종목<br><Br>";
  
  


                     # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name  order by no desc";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);

																											#print_r($thema_srch_array['keys']);

                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기



                     # 시작 :전체 종목 명 가져오기
																										   $arr_stock_srch['qry']="select stock_code,stock_name from all_stock_info";																											
																										   $arr_stock_srch['keys'] ='stock_code';
																										  # $arr_stock_srch['multi_keys'] =0;
 
																											$stock_srch_array=php_mysql_Query($arr_stock_srch,$connect);

                                                                                                          # 종목 네임 배열
																											$all_stock_name=$stock_srch_array['multi_keys'];

																											#print_r($all_stock_name);
                     # 끝 :전체 종목명 가져오기








  # 변수 할당
 
 
  if($G_No ) {

								  $arr_qry['qry']="select * from tbl_daily_report where no='$G_No' ";
								  $result=php_mysql_Query($arr_qry,$connect);     

								  $report=$result['value'][0];

								
								  # $rtime=calender_str(1,0,$value['rtime']);
									#$yy=date("y",$value['rtime']);

								  # 첨부파일 불러오기
								#  $attach_file_tag=attach_file_Display($value['attach_file'],$value['no'],1,0);

								$default_cts=$report['contents'];

	  }
 

$today_ptime=calender_str(1,0,time());
$rtime=$today_ptime['unix_str'];


 
$base_Date_Str="<tr><td></td><td><table style='font-size:9pt;'  align=left><tr><td>
				 <img src='../img/ic/6.gif' title='시작일'>
			     <input type='text' name='rtime'  id='rtime' value='$rtime' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime','','0')\" style='cursor:hand'></td></tr></table></td></tr>";


#$cycle_type_tags="<tr><td><input type=\"hidden\" name=\"cycle_type\" value=\"0\"></td></tr>"; 



  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>GR_Vals</title>
    
			 $style_css


		

	<script language=\"javascript\">
     
			 
			 function       chkfrm(f) {	         
				
  if (f.title.value == '')
                      {
		        alert('제목을 입력하세요!');
		        f.title.focus();
		        return;
	              }
	
	    
     	  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
          contents= document.getElementById('ir1').value;

		
	if (contents== '<p>&nbsp;</p>' || contents== '') {
		alert('내용을 입력하여 주십시오');
		return false;
	}
						 


 //  var chbox = document.getElementsByName(\"upfile_chk[]\");

 //  alert(chbox.length);
 // for(var i = 0; i<chbox.length; i++){
//     if(chbox[i].checked ==true){ 
  //         alert(1);
  //  } 



    f.submit();	
	
      }

	 </script>



	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['news_d3'].">

        <table width=".$tbl_width['news_d3']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


        <tr>
  
		            <td valign=top > 

		";

# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		

		
		<table width=100%  border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"updatE\">
		<input type=\"hidden\" name=\"no\" value=\"".$G_No."\">

		       <tr><td colspan=2><a href='$cur_php?mode=report_lisT'>list</a> | <a href='javascript:window.location.reload();'>새로고침</a></td></tr>


			   <tr align=\"LEFT\" valign=\"MIDDLE\">
				  <td align=left style='padding-top:5px;font-size:12px;' nowrap>

					 <img src='../img/ic/ic_pen02.gif'> </font>제 목
				  </td>


				  <td colspan=3>	            
					  <input type=text size='80' name='title' id='title' class=form_nc value=\"".$report['title']."\">
				 </tr>


	  

				 <tr align=\"left\">
				 <td></td>

					 <td align='left' style='padding-top:15px;' colspan=4>     

					   <img src='../img/ico_tag.gif'><input type=text size='65' name='tags' value=\"".$report['tags']."\" class=form_nc>

							  &nbsp; &nbsp; &nbsp; <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>                    
								 <img src='../img/cafe_unlock.gif' title='* 비밀번호를 입력하지 않으면 글을 수정하거나 삭제하실 수 없습니다.'>
								 <input type=\"Password\" name=\"usrpwd\" value=\"".$report['usrpwd']."\" size=\"15\" maxlength='8' class=form_nc>          
								 $calender_js
								 				  		
											 
					 </td>
					 
					</tr>
					 

");


echo $base_Date_Str;


	echo ("

   <tr>
		<td colspan=4>								 
  ");

				# 시작 :스마트 에디터 불러오기
				echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
				echo"<textarea name=contents id=\"ir1\" style=\"width:755px; height:812px; display:none;\">$default_cts</textarea>";

				echo ("
								<script type=\"text/javascript\">

								  var oEditors = [];

								  nhn.husky.EZCreator.createInIFrame({

									oAppRef: oEditors,

									elPlaceHolder: \"ir1\",

									sSkinURI: \"../smart_editor/SmartEditor2Skin.html\",

									fCreator: \"createSEditor2\"

								});
						 
								</script>
					   ");
				# 끝: 스마트 에디터 불러오기

echo "		</td></tr>";

## 이미지를 불러오고 삽입하는 함수

 # 기본 이미지 사이즈는 insert_attach.php 에서 조정이 가능


echo "
      <script language=\"javascript\">

       // 업데이트 디렉토리와 이름구분자를 변수로 받아서 insert_attach.php 파일에 넘겨줌.

	   function      InSeRt_AttAch(up_dir) {
		                                       
												var Up_Dir = up_dir;
					                            var popup_X = event.screenX;	
												 var popup_Y = event.screenY;

												 var urls='smart_editor/insert_attach.php?mode=get_dir_file&dir_st='+Up_Dir+'';
											     var zz;

											     zz = window.open(urls, 'newWindow', 'width=500, height=500,left='+popup_X+',top='+popup_Y);

												  zz.focus();

											}

				count=0;

				
   function      attach_file_fnc(Display_File_Name,File_Str_Array) {
                  	        		                   
 					var Up_File_Array = Array();
					Up_File_Array = File_Str_Array.split('&%&');  // [0]이미지파일여부 [1] 확장자 [2] 표시이름 [3] 실제이름 [4] 서버 경로 [5] 가로 [6] 세로

				 if(Up_File_Array[0]==1) {
						      
							Attach_List_Tag='pasteHTM(\' <img src='+ Up_File_Array[4] +Up_File_Array[3]+' width= '+ Up_File_Array[5] +'  height= '+ Up_File_Array[6] +' >\');'; 			
													
						}
				  
				  else {

                        Attach_List_Tag='pasteHTM(\' <a onclick=DownLoad_Attach(' + Up_File_Array[4] + ')> 다운로드:' + Display_File_Name + '</a> \');'; 			


				  }

					count++;

					var newSpanItem = document.createElement(\"span\"); 
					newSpanItem.setAttribute(\"id\",count); 

					var msg_str = '<table><tr><td style=font-size:10px;><input type=checkbox name=upfile_chk[] value=\"'+count+'\" checked><input type=hidden name=upfile_chk_hidden[] value=\"'+count+'\" checked><input type=\"button\" onclick=\"'+ Attach_List_Tag +'\" value=\''+ Display_File_Name +'\' class=form_nc><input type=hidden name=upfile[] value=\''+File_Str_Array+'\'></td></tr></table>';
											
					newSpanItem.innerHTML = msg_str; 	   

					
					var itemListNode = document.getElementById('itemList'); 
					itemListNode.appendChild(newSpanItem); 
		}


           // 클릭시 이미지를 본문에 삽입, image 태그를 삽입. 가로,세로 사이즈 알아내서  

                function      pasteHTM(input_str) {
					 //alert(input_str);
					oEditors.getById[\"ir1\"].exec(\"PASTE_HTML\", [input_str]);
					
				}
			   

                function      pasteHTM_decode(input_str) {

					  input_str=decodeURIComponent(input_str); 
					 //alert(input_str);
					oEditors.getById[\"ir1\"].exec(\"PASTE_HTML\", [input_str]);
					
				}


	  </script>

";


  $up_Base_Dir="../dta/report/2022"; 


  #  다운로드는 파일명.. 디렉토리 번호를 넘겨준후에.. 모달창에서 다운로드 받도록 함. 다운로드 할수 있도록 함.

 ## 이미지를 불러오고 삽입하는 함수


$tbl_insert_cts="<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px dashed #c7c7c7; border-left:0; border-bottom:0;\" attr_no_border_tbl=\"1\" class=\"__se_tbl\">
<tr><td style=\"border:1px dashed #c7c7c7; border-top:0; border-right:0; background-color:#ffffff\" width=\"30\">&nbsp;
</td><td style=\"border:1px dashed #c7c7c7; border-top:0; border-right:0; background-color:#ffffff\" width=\"600\"></td></tr>
</table>";
 
$tbl_insert_cts=rawurlencode($tbl_insert_cts);  # 자바스크립트에 변수 넘겨준후 다시 자바스크립트에서 decode 할때  호환되게 하려면.. 


echo "  

			<tr align=left height=40>
				<td style='font-size:12px;'><a onclick=\"InSeRt_AttAch('$up_Base_Dir');\" style='cursor:hand'><img src='../img/sweety/8-em-heart.png'>첨부</a>:: </td><td>$attach_file_tag<span id='itemList'></span></td>
				</td>
            </tr>	


			<tr align=left height=40>
				<td style='font-size:12px;'><img src='../img/sweety/8-em-heart.png'><a onclick=\"pasteHTM_decode('$tbl_insert_cts')\">테이블</a> 
				</td>
            </tr>	


   	 				

   <tr class=n1 align=\"CENTER\" valign=\"MIDDLE\">
   <td colspan=4>
   <br>
    <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>
    </form>
 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 



echo "</td>
      </tr>";



echo "<tr><td>기사불러오기</td></tr>";

echo "<Tr><td colspan=10><table style='font-size:12px;' border=0>";
	  	

$arr_scrap['qry']="SELECT * FROM `tbl_news_scrap`  order by uDate desc limit 0,30";
$get_scrap_news=php_mysql_Query($arr_scrap,$connect);

#$scrap_thema_tags="";


foreach($get_scrap_news['value'] as $s_key => $s_value){

$subject_str="";

               if(($s_value['thema_no'])!=0)   $subject_str=$all_thema_name[$s_value['thema_no']]['thema_name'];   #echo $subject_str."<br>";}
               elseif(!empty($s_value['stock_code'])) $subject_str=$all_stock_name[$s_value['stock_code']]['stock_name'];
			   else $subject_str="market";


		                                 	  $up_day=date_str(12,1,strtotime($s_value['uDate'])); # 날짜를 스트링으로 표시

																																											$scrap_thema_tags="<Tr><td>".$up_day['str']."</td> ";

																																											$scrap_thema_tags.="<td nowrap>".$subject_str."</td> ";

																																											$scrap_thema_tags.="<td nowrap><a href=' ".$s_value['news_link']."' target='news_u5' style='text-decoration-line: none;' onclick=\"read_change_color('btn_srch_tsi',".$stn.");\"><span id='btn_srch_tsi_$tsi' style='color:#08088A;'>".$s_value['news_title']."<span></a> &nbsp; "; 

																																											$scrap_thema_tags.="</td></Tr>";

																																													$stn++;



  echo $scrap_thema_tags;

}


echo "</table></td></tr>";



echo "
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";
echo "</tR></table>";

echo "</body></html>";


 ################### end of write_form #######################
}
################### end of write_form #######################




################### start of reporT_update #######################
 function reporT_List($connect) { 
################### start of reporT_update #######################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');


  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>GR_Vals</title>
    
			 $style_css


		

	<script language=\"javascript\">
     
			 
			 function      del_no(id_no) {	         

				
  if (confirm(\"삭제하시겠습니까?\")) {

    		 go_to_url_tags  ='$cur_php?mode=rl&del_no=\''+id_no+'\'';		
             window.document.location.href=go_to_url_tags;
	
      }

 }

	 </script>


	";


#


if($GR_Vals['del_no']) {

$arr_del['qry']="delete FROM `tbl_daily_report` where no=".$GR_Vals['del_no']." ";
$arr_del['result']=1;
php_mysql_Query($arr_del,$connect);

#print_r($arr_del);



}



$arr_report['qry']="SELECT * FROM `tbl_daily_report`  order by uDate desc ";  # limit 0,30
$get_report_news=php_mysql_Query($arr_report,$connect);


echo "<table style='border: 0px dashed orange; border-radius: 10px; border-spacing:10px;".$mobile_font_array['title']." padding:1px;'  width='100%'> "; # start of 1번째  tbl



   echo "<tr><td colspan=2 style='border-radius: 10px; border-spacing:3px;padding:10px;background-color:#A9D0F5;' width=95%> <a onClick=\"window.location.href='$cur_php?mode=rl';\" style='cursor:hand;'> 시황</a> <a href='$cur_php?mode=write'><img src='../img/pen.gif'></a></td></tr>";


  echo "<tr><td>";

  echo "<table  style='border: 0px dashed orange; border-radius: 10px; border-spacing:5px;background-color:#EFF2FB;' border=0 width=100%>";

  foreach($get_report_news['value' ] as $no => $report_news) {

	     $nr++;
   
       echo "<tr><td width='20px;'>$nr</td>";

	   echo  "<td width='600px;'><a href='$cur_php?mode=report_reaD&no=".$report_news['no']." '>".$report_news['title']."</td><td><a href='$cur_php?mode=write&no=".$report_news['no']." '>M </a> <a onclick=\"del_no('".$report_news['no']."');\"><img src='../img/ic/12-em-cross.png'></a></td>";
	   
	   echo "</tr>";
   

  }

echo "</table></td></tr>";







echo "</table>";


exit;







 ################### end of reporT_update_form #######################
}
################### end of reporT_update_form #######################





################### start of reporT_update #######################
 function reporT_reaD($connect) { 
################### start of reporT_update #######################
global $cur_php;

require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');


echo "<img src='../img/bul60.gif' onClick=\"window.location.reload();\"> ";


$arr_report['qry']="SELECT * FROM `tbl_daily_report`  where no='".$GR_Vals['no']."'  ";  # limit 0,30
$get_report_news=php_mysql_Query($arr_report,$connect);

 $value=$get_report_news['value'][0];


echo "<html><body>";

echo $style_css;



   
  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>report</title>    
			 $style_css
			 $calender_js
		</script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\"  onLoad='document.myform.title.focus();'>";



echo "

        <table width=".$tbl_width['news_d3']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td> 
        ";



       echo "<table  style='font-size:15px;'>";



	    echo "<Tr><td>제목</td><td> ".$value['title']."</td></tr>";

       echo "<Tr><td colspan=2>".$value['contents']."</td></tr>";





      echo "</table>";













echo "</td><tr></table>";

echo "</body></html>";



exit;







 ################### end of reporT_update_form #######################
}
################### end of reporT_update_form #######################







############################################
function report_iFrame() {
###########################################

global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";
$GR_Vals=Get_Vals('mode');


#print_r($GR_Vals);


echo"<html><body  width=100%>

  	<table height=100% width=100% border=1>
   
   <tr valign=top>

		<Td width=640>

										<iframe src='get_finance_info.php ' id='res_frame0' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news0' ></iframe>

		</td>

       <Td width=720>

										<iframe src='$cur_php?mode=write' id='res_frame1' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news1' ></iframe>

		</td>
		

       <Td width=600>
										<iframe src='https://kr.investing.com/' id='res_frame2' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news2' ></iframe>

		</td>


       <Td width=600>

										
										<iframe src='https://m.stock.naver.com/' id='res_frame3' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news3' ></iframe>

		</td>


       <Td width=600>

										<iframe src='https://kr.investing.com/' id='res_frame3' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news3' ></iframe>

		</td>


		
		";

										echo "</tr></table>";


										echo "</body></html>";



 ################### end of  Pax_iFrame() #######################
}
################### end of  Pax_iFrame() #######################







################### start of reporT_update #######################
 function reporT_update($connect) { 
################### start of reporT_update #######################

require "./env/e.fnc";

#시작: 변수정의
	$up_qry="";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');

#	$rtime=time();
# 끝: 변수정의

#print_r($GR_Vals);

$up_day=explode(" ",$GR_Vals['rtime']);




#첨부파일 관리
$GR_Vals['attach_file']=attach_file_mng($GR_Vals['upfile'],$GR_Vals['upfile_chk'],$GR_Vals['upfile_chk_hidden'],$GR_Vals['upfile_old_display_name'],$GR_Vals['upfile_new_display_name'],$GR_Vals['pims_no']);


$GR_Vals['contents']= preg_replace("/tempUpFile/", "".$GR_Vals['no']."",$GR_Vals['contents'],-1,$count); # 임시파일명이 발견된다면

$GR_Vals['contents']=addslashes($GR_Vals['contents']);

$skip_Array=array('upfile','upfile_chk','upfile_chk_hidden','upfile_old_display_name','upfile_new_display_name','upfile','rtime','no','uDate','tags');



# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($GR_Vals as $reporT_key => $reporT_value) { # start of for

					  if(in_array($reporT_key,$skip_Array)) continue;

								  #if($pims_key == 'cycle_data') $pims_value=trim($pims_value);
								 #  if($pims_value=="") $pims_value=0;

								  $up_qry.="$reporT_key='$reporT_value',";
} # end of for

$up_qry.="tags='".$GR_Vals['tags']."'";

# 끝: 받은 자료를 가지고 쿼리로 만듬



if($GR_Vals['no']) {

	$query_ins="update tbl_daily_report set   $up_qry where no='".$GR_Vals['no']."'   ";
}

	else {
	$query_ins="insert into tbl_daily_report set $up_qry";

	}



	$result_ins=mysqli_query($connect,$query_ins); 

#	echo $query_ins;








if($result_ins) {
			 Header("Location:$cur_php?mode=report_lisT");
	        }
 

#       mysqli_close($connect);





exit;







 ################### end of reporT_update_form #######################
}
################### end of reporT_update_form #######################




################### start of attach_file_mng #######################
function      attach_file_mng($upfile,$upfile_chk,$upfile_chk_hidden,$upfile_old_display_name,$upfile_new_display_name,$no) {
################### start of attach_file_mng #######################

if($upfile) {
							foreach ($upfile as $file_key => $file_value){

							   if(in_array($upfile_chk_hidden[$file_key],$upfile_chk)) {  # 	
								   
   															 $count=0;

															 preg_replace("/tempUpFile/", "$no",$file_value,-1,$count); # 임시파일명이 발견된다면

															 if($count>0) {  
																 
															 $old_File_Array=explode("&%&",$file_value);
															 
															 $file_value=preg_replace("/tempUpFile/", "$no",$file_value,-1,$count);

															 $new_File_Array=explode("&%&",$file_value);

															   rename ($old_File_Array[4].$old_File_Array[3], $old_File_Array[4].$new_File_Array[3]); # 파일명을 변경함
															 
															 }

                                                             if($upfile_old_display_name[$file_key]<>$upfile_new_display_name[$file_key]) { 
																 $file_value=preg_replace("/$upfile_old_display_name[$file_key]/",$upfile_new_display_name[$file_key] ,$file_value);
																 }



								                             $attach_file_str.="$file_value&^^^&"; 
							   							   
							                              }
								 else { 
										$delete_file_info=explode("&%&",$file_value);
									 echo "<br>삭제: $delete_file_info[4]";
									 unlink($delete_file_info[4].$delete_file_info[3]);
								 
								 }

							 }
} else $attach_file_str="";

return $attach_file_str;




################### end of attach_file_mng #######################
}
################### end of attach_file_mng #######################






#################################################################
function thema_news_read ($connect) {
#################################################################
global  $style_css;
global  $dot_line;

global  $cur_php;
global  $GR_Vals;
global $key_word;
global $max_pages;
global $G_thema_no;
global $G_stock_code;


#print_r($GR_Vals);

# 테마번호가 있는 경우에는 테마주를 넣어서 구글에서 검색
if(!empty($G_thema_no) or !empty($G_stock_code) or !empty($max_pages)) $add_str=" 테마주"; 


# " ,' 등 특수문자제거

$key_word= preg_replace("/[ #\&\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $key_word);

$g_key_word= str_replace(' ',"+",$key_word.$add_str);

$google_srch_Tags="<a href='https://www.google.com/search?q=".$g_key_word."'  target=_blank>구글검색?</a>";
#echo $google_srch_Tags;


include "shd/simple_html_dom.php";

											## 시작 : 최근에 등록한 테마 리스트

											$thema_key_word="";

																						$query_thema="SELECT * FROM `tbl_thema_name` order by uDate desc limit 15 ";
																						$result_thema=mysqli_query($connect,$query_thema); 


																					 while($thema_str=mysqli_fetch_array($result_thema)) {


																											   $thema_key_word.="<a href=$cur_php?mode=news_read&key_word=".$thema_str['thema_name']."&thema_no=".$thema_str['thema_no'].">".$thema_str['thema_name']."</a> | ";		
																								}


																					  $thema_list_tags= "[최근 등록 테마] ". $thema_key_word;

																			
											## 끝 : 가장 최근에 등록한 테마 리스트



										
											## 시작 : 가장 최근에 등록한 종목명


											                                          $stock_code_key_word="<table class=n1s>";


																						$query_stock_code="SELECT DISTINCT  tsc.stock_code,tsc.stock_name,tsc.uDate,tdts.thema_no,ttn.thema_name FROM `tbl_stock_code` AS tsc  LEFT OUTER JOIN `tbl_daily_thema_stock` AS  tdts ON tdts.stock_code = tsc.stock_code  left join tbl_thema_name as ttn on tdts.thema_no = ttn.thema_no where tsc.uDate = (SELECT uDate FROM `tbl_stock_code` order by uDate desc limit 1 )";

																						$result_stock_code=mysqli_query($connect,$query_stock_code); 

																						  # 결과값을 테마로 배열정렬함
																						 while($stock_code_str=mysqli_fetch_array($result_stock_code))      											   $stock_thema_array[$stock_code_str['thema_no']][]=$stock_code_str;

																						 sort($stock_thema_array); # 테마명으로 정렬


																						foreach( $stock_thema_array as $stock_array) {

																							   if(!empty($stock_array[0]['thema_no']))      $stock_code_key_word.= "<tr><td width=180><img src='../img/cb.gif'><a href=$cur_php?mode=news_read&key_word=\"".$stock_array[0]['thema_name']."\"&thema_no=".$stock_array[0]['thema_no']."><font  style='color:red'> ".$stock_array[0]['thema_name']." </font></td><td> ";
																							   else $stock_code_key_word.= "<tr><td><img src='../img/cb.gif'><font  style='color:black'> 개별 </font></td><td> ";


																								for($ti=0;$ti<count($stock_array);$ti++)	 {
																											 
																																$stock_code_key_word.= "<a href='$cur_php?mode=news_read&key_word=\"".$stock_array[$ti]['stock_name']."\"&stock_code=".$stock_array[$ti]['stock_code']."&thema_no=".$stock_array[$ti]['thema_no']."'>".$stock_array[$ti]['stock_name']."</a>  " ; 

																																if(count($stock_array)>1) {

																																	$stock_code_key_word.= " | ";

																																}


																								  }

																								  $stock_code_key_word.= " </td></tr>";

																								 
																								  $stock_code_list_tags= $stock_code_key_word."</table>";

																							}




											## 시작 : 테마 코드가 있다면... 테마테이블에 등록된 내용들 가져올것

											$thema_story_word="<tr>$dot_line</tr>";
											$thema_story_title="";

																						$query_thema_story="SELECT tdts.uDate,tdts.thema_no,tdts.thema_story ,ttn.thema_name FROM `tbl_daily_thema_story` as tdts left join `tbl_thema_name`  as ttn on  tdts.thema_no=ttn.thema_no where  tdts.thema_no='$G_thema_no'";
																						$result_thema_story=mysqli_query($connect,$query_thema_story); 

																						#echo $query_thema_story;


																					 while($thema_story_array=mysqli_fetch_array($result_thema_story)) {

																												$thema_story_title= $thema_story_array['thema_name'];

																											   $short_story=shorten_Str($thema_story_array['thema_story'],110,"..");

																											   $thema_story_word.= "<tr><td width=80>".$thema_story_array['uDate']."</td><td width=450>".$short_story."</td></tr><tr>$dot_line</tr>";		


																								}


																					  #$thema_list_tags= "[특징테마] ($thema_day) ". $thema_key_word;

																		$thema_story_tags=	  "<table class=n1s>".$thema_story_word."</table>";


											## 끝 : 테마 코드가 있다면... 테마테이블에 등록된 내용들 가져올것



											## 시작 : 테마 코드가 있다면... 테마 종목 테이블에 등록된 내용들 가져올것

											$thema_daily_stock_word="";

																						$query_thema_daily_stock="SELECT *, tdts.uDate as rDate from tbl_daily_thema_stock as tdts left join tbl_stock_code as tsc on tdts.stock_code= tsc.stock_code where tdts.thema_no='$G_thema_no' order by tdts.stock_code,tdts.uDate desc";
																						$result_thema_daily_stock=mysqli_query($connect,$query_thema_daily_stock); 


																					 while($thema_daily_stock_array=mysqli_fetch_array($result_thema_daily_stock)) {

																											   $thema_daily_stock_word.= "<tr><td width=80>".$thema_daily_stock_array['rDate']."</td><td><a href=\"$cur_php?mode=news_read&key_word='".$thema_daily_stock_array['stock_name']."'&stock_code=".$thema_daily_stock_array['stock_code']."&thema_no=".$thema_daily_stock_array['thema_no']."\">".$thema_daily_stock_array['stock_name']."</td></tr><tr>$dot_line</tr> ";		
																								}


																					  #$thema_list_tags= "[특징테마] ($thema_day) ". $thema_key_word;

																		$thema_daily_stock_tags=	  "<table class=n1s>".$thema_daily_stock_word."</table>";

											#echo $thema_daily_stock_tags;

											## 끝 : 테마 코드가 있다면... 테마 종목 테이블에 등록된 내용들 가져올것

											## 구글에서 한번에 10개씩 가져와서 

											if($max_pages==1) 	$start_num_array=array(0,10,20,30,40);  # 폼input을 통한 검색(특징주)인 경우 최대 50개까지 보여줄것
																			else     $start_num_array=array(0,10,20);

																							$an=1;


																		foreach($start_num_array as $start_num) {
																																															
																																															   ## https 는 안됨.  뉴스와 전체인경우 구분해야 함. 어떻게?


                                                                                                                                                                                                 #  $google_url ="http://www.google.com/search?q='".$g_key_word;

																																																$google_url ="http://www.google.com/search?q='".$key_word."'&tbas=1&biw=1449&bih=1562&tbs=qdr:w&tbm=nws&start=$start_num&lr=lang_ko";

																																																#echo $google_url;

																																																$get_html=file_get_html($google_url);
																																																
																																																#echo $get_html;

																													foreach($get_html->find('div.Gx5Zad.fP1Qef.xpd.EtOod.pkphOe') as $link_res) {
																																																   

																																																																						  $link=$link_res->find('a',0)->href;

																																																																						  $src_site=$link_res->find('div.BNeawe.UPmit.AP7Wnd',0)->plaintext;

																																																																						   $title=$link_res->find('div.BNeawe.vvjwJb.AP7Wnd',0)->plaintext;

																																																																						   
																																																																							$up_day=$link_res->find('span.r0bn4c.rQMQod',0)->plaintext;

																																																																						  # $title = urldecode($title);


																																																																						  # 구글에서 한글페이지만 검색하는 옵션 lr=lang_ko 을 선택했을때.. euc-kr로 리턴되는 듯. 이를 다시 utf-8로 변경

																																																																						  $title = iconv("EUC-KR", "UTF-8", $title);

																																																																						  $title= shorten_Str($title,25,'..');


																																																																						  $src_site = iconv("EUC-KR", "UTF-8", $src_site);
																																																																						  $up_day = iconv("EUC-KR", "UTF-8", $up_day);


																																																																						   ### 업데이트 날짜가 2일 전, 1시간 전  이렇게 txt 형태로 되어 잇어 정렬이 어려움. 텍스트 제거후 일자에는 24을 곱해줘서 시간과 구분해줌.
																																																																							  if(strstr($up_day,"일 전")) 	  {
																																																																																						 $up_day_num=intval(substr($up_day,0,-7))*24*60; 
																																																																																					 
																																																																																					 }


																																																																									else if (strstr($up_day,"시간 전"))			{					 $up_day_num=intval(substr($up_day,0,-7))*60;															
																																																																																															   $up_day="<font style='color:red;'>$up_day</font>";
																																																																																												  }

																																																																										else if (strstr($up_day,"분 전"))			{					 $up_day_num=intval(substr($up_day,0,-7));															
																																																																																															   $up_day="<font style='color:red;font-weight:bold;'>$up_day</font> <img src='../img/si_n.gif'> ";
																																																																																												  }
																																																																								
																																																																									

																																																																							$Find_Link[$up_day_num][]="[$src_site] <a href='https://google.com".$link."'  target='news1' style='font-size:17px;'>$title</a> $up_day&nbsp; &nbsp; <a href='http://google.com".$link."'  target='news2' style='font-size:17px;'><img src='../img/imoticon/num4/02.gif'></a>";

																																																																						  #  $Find_Link[] = array('up_day' => $up_day_num, 'cts' => "$an <font style='font-size:12px;'>[$src_site] <a href='http://google.com".$link."'  target='news' style='font-size:17px;'>$title</a> $up_day");

																																																																			}

												}


											ksort($Find_Link);

											$colspan_num=4;
     
   # 검색 링크

   $search_Tags ="
											<input type='hidden' name=mode value='news_read'>
											<input type='hidden' name=max_pages value=1>
											 <input type='text' name='key_word' value='특징주' size='14'  class=form_nc  onFocus=\"clearField(this)\" >						 
											 <input type=submit value=\"검색\"  class=form_nc style='cursor:hand'></form>";


# 종목코드가 있다면?

if($G_stock_code) $infostock_open="<a href='https://www.infostock.co.kr/site/3d/3d_show.asp?codename=$G_stock_code' target='news2'>";




											echo "<html><body>";

											   echo $style_css;


											echo ("
															   <script type=\"text/javascript\">
																	function      calcHeight() {
																		var the_height =document.getElementById('res_frame').contentWindow.document.body.scrollHeight;
																		document.getElementById('res_frame').height = the_height;
																		document.getElementById('res_frame').style.overflow = \"hidden\";

															 //  alert(the_height);

																	}
																</script>
												");

											echo "<table border=0 height=100% width=100%><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	";



                                           echo "<Tr valign=top><td width=1330>";  # 왼쪽 테이블


										                 echo "<table border=0 class=n1s>";


																						echo  "<tr valign=top><Td  colspan=$colspan_num> <a href='rss_feed.php' target='_blank'>테마리스트</a> |   <a href='$cur_php?mode=file_attach' target='_blank'>테마 종목 올리기</a> |   <a href='pax_news.php' target='_blank'>팍스뉴스</a> </td></tr>";
																						echo $dot_line;
																						echo  "<tr valign=top><Td  colspan=$colspan_num>  $thema_list_tags </td></tr>";
																						echo $dot_line;
																						echo  "<tr valign=top><Td  colspan=$colspan_num>  $stock_code_list_tags </td></tr>";

																						echo $dot_line;


																						#echo "<tr height=30><td></td></tr>";


																						echo "
																										<tr valign=top>

																													<Td width=600><img src='../img/go_on.gif'> 검색어(<a href='$cur_php?mode=news_read&key_word=특징주'>특징주</a>, $google_srch_Tags) ::  $infostock_open$key_word</a><span style='display:inline-block; width:100px;'></span>  <img src='../img/dot_r.gif'> $search_Tags </td>
																													<Td width=550><img src='../img/go_on.gif'> <font style='color:red;font-size:14px;font-weight:bold;'>$thema_story_title</font> 테마 관련 내용</td>
																													<Td width=230><img src='../img/go_on.gif'> 테마 관련 종목 </td>
																										</tr>
																									";


																						echo $dot_line;

																						echo "<tr valign=top><Td width=800 class=n1s>";


																						foreach( $Find_Link as $key_array) {
																							
																						   foreach( $key_array as $key_value) {	echo "<".$an."> ".$key_value."<br><br>"; $an++; }

																						}

																						echo "</td>";

																						echo "<td>$thema_story_tags</td>";

																						echo "<td>$thema_daily_stock_tags</td>";

																echo "</table>";

										echo "</td>";  # 왼쪽 테이블


   										    echo "<td  valign=top>";  # 오른쪽 테이블

																		echo "<table height=100% width=100%  ><tr>
																		<td><iframe src='' id='res_frame' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news1' ></iframe></td>

																		<td><iframe src=' ' id='res_frame' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news2' ></iframe></td>
																										
																		
																		</tr></table>";

										echo "</td>";  # 오른쪽 테이블


											echo "</tr></table>";


											echo "</body></html>";

											exit;

#################################################################
} # end of thema_news_read
#################################################################


#################################################################
function      thema_file_attach ($connect) {
#################################################################
global $cur_php;


require "./env/inf.fnc";
#require "./env/fnc/sub_fnc/chg_css.php";
#require "./env/fnc/sub_fnc/auto_checkbox.php";


$today_ptime=calender_str(1,0,time());
$start_time=$today_ptime[unix_str];


#  header('Content-Type: text/html; charset=utf-8');

  echo "<font color=white> $today" ;


			echo "	<html>
				  
					$style_css
					$calender_css

					$calender_js

					<BODY bgcolor=\"#232845\" leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" background='$bg_img[0]' style='font-size:11px;'>
					
					<table align=\"center\" border=1 cellspacing=\"0\" cellpadding=\"0\"  style='font-size:12px;' bgcolor=white>
					  
									   <tr>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
												<input type='hidden' name=mode value='thema_file_up'>
											  <input type='text' name='thema[start_time]'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform.start_time','','0');\" style='cursor:hand'>
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>
									
						</table>
					


					</body>
				</html>
				 ";


#################################################################
} # end of admin_write
#################################################################



#################################################################
function thema_file_update ($adminID,$thema,$upfile,$connect)  {
#################################################################
global $cur_php;


$file_open=fopen($_FILES['upfile']['tmp_name'],"r");
$file_Bytes=$_FILES['upfile']['size'];

$file_cts=fread($file_open,$file_Bytes);

$file_line_cts = explode("\r\n", $file_cts);
$up_day=explode(" ",$thema['start_time']);


$test_No=0;



                                                 # 오늘 등록된 테마 종목들은 일괄 삭제
															$query_del_daily_stock="delete from tbl_daily_thema_stock  where  date(uDate)='$up_day[0]'  ";
									if(!$test_No)	$result_del_daily_stock=mysqli_query($connect,$query_del_daily_stock); 

															
															#echo $query_del_daily_stock;

												  # 오늘 등록된 테마 스토리를 삭제

															$query_del_daily_story="delete from tbl_daily_thema_story  where  date(uDate)= '$up_day[0]'  ";
						if(!$test_No)			$result_del_daily_story=mysqli_query($connect,$query_del_daily_story); 

															#print_r($result_del_daily_stock);

# 업데이트 파일 test
if($test_No==1) {

	foreach($file_line_cts as $no=>$stock_info) {


  echo"<table border=1>";

		  		if($stock_info=="") continue;

					$stock_info_array= preg_split("/[\t]/", $stock_info);

					echo "<tr>";
				     echo "<td>$no</td>";

                  for ($k=0 ; $k< 10;$k=$k+1) {

					  echo "    <td>($k)</td><td>$stock_info_array[$k]</td>";


				  }
					  

				   echo  "<td>$stock_info_array[8]</td>" ;
				   echo  "<td>empty (".empty($stock_info_array[8]). " )</td>  ";

				   echo "</tr>";
				}


 echo "</table>";
exit;
}
$tt=0;

		foreach($file_line_cts as $no=>$stock_info) {

			$tt++;
			  
              if($stock_info=="") continue;

				# 제거할 문자 ->  코멘트 " 제거
			   $stock_info= str_replace('"',"",$stock_info);

							    if($test_No==1)    echo $stock_info."<br>";

							  $stock_info_array= preg_split("/[\t]/", $stock_info);
							  # 0. 종목명 1.종목코드 2.시총 3.거래량 4.변동률 5.pbr 6.영익률 7.배당률 8.테마명 9.테마내용
								
		 # 1) 시작	: 종목명과 종목코드를 최신으로 업데이트 함
								
								$query_code="select * from tbl_stock_code where stock_code='$stock_info_array[1]'";
								$result_code=mysqli_query($connect,$query_code); 


             if($test_No==2)     echo $query_code."<br>";


																if (!$result_code->num_rows) {  # 기존에 등록된 종목이 아니라면 신규 입력

																																	$query_ins_tsc="insert into tbl_stock_code set stock_code='$stock_info_array[1]',stock_name='$stock_info_array[0]',uDate='$up_day[0]'";
																																	$result_ins_tsc=mysqli_query($connect,$query_ins_tsc); 

																																if($test_No==2)	echo $query_ins_tsc."<br>";

																															}
																	else        # 기존에 종목된 종목코드를 기준으로 종목명을 업데이트 시킴 ( 기업명 변경 케이스 )
																			{							               

																																	$query_update_tsc="update tbl_stock_code set  stock_name='$stock_info_array[0]',uDate='$up_day[0]' where stock_code='$stock_info_array[1]' ";
																																if(!$test_No)	$result_update_tsc=mysqli_query($connect,$query_update_tsc); 

																																if($test_No==2)	echo "$query_update_tsc.<br>";
																			}

		 # 1) 끝	: 종목명과 종목코드를 최신으로 업데이트 함
		 
   if($test_No==2) continue;


					   if(!empty($stock_info_array[9])) { # 테마 내용이 있는 경우에만


												#  2) 시작 :: 테마명을 신규로 입력
																												$query_thema_name="select * from tbl_thema_name  where thema_name='$stock_info_array[8]'";
																											   	$result_thema_name=mysqli_query($connect,$query_thema_name); 

																												   if($test_No==3)  echo $query_thema_name."<br>";

																												   	$thema_name_info = mysqli_fetch_array($result_thema_name);

																												  

																																					                                                               if( is_Null($stock_info_array[8])) {   # 기존에 등록된 테마 네임이 없고, 테마명이 있
																																																																				
																																																																														$query_ins="insert into tbl_thema_name set thema_name='$stock_info_array[8]',uDate='$up_day[0]'";
																																																																						   if(!$test_No) 	$result_ins=mysqli_query($connect,$query_ins); 

																																																																						   if($test_No==3)  echo $query_ins."<br>";

   																																																			                                                                     	   }

																																																			

												# 2) 끝 :: :: 테마명을 신규로 입력

   if($test_No==3) continue;



												#  3) 시작 :: 테마명을 가지고 테마번호를 가져온후 개별 종목 업데이트

																												$query_find_thema="select * from tbl_thema_name  where thema_name='$stock_info_array[8]'";
																											 	$result_find_thema=mysqli_query($connect,$query_find_thema); 


																											   $thema_info = mysqli_fetch_array($result_find_thema);

																											   if($test_No) { print_r($thema_info);
																											   echo "<Br>"; }

																											 	$today_thema_info[$thema_info['thema_no']]=$thema_info['thema_name']; 


																												$stock_info_array[9]=addslashes($stock_info_array[9]); ## 따옴표가 있으면.. 슬래시를 붙여줌


																											# no (int5),  stock_code(char 6),  stock_cap (int  ),  stock_vol (int)  stock_rate()  stock_pbr ()  stock_opm()  stock_div_rate()   thema_no(int 5)
																										
																											$query_daily_ins="insert into tbl_daily_thema_stock set stock_code='$stock_info_array[1]',stock_cap='$stock_info_array[2]',stock_vol='$stock_info_array[3]',stock_rate='$stock_info_array[4]',stock_pbr='$stock_info_array[5]',stock_opm='$stock_info_array[6]',stock_div_rate='$stock_info_array[7]',thema_no='$thema_info[thema_no]',thema_story='$stock_info_array[9]',uDate='$up_day[0]'";


													
											                                                      if(!$test_No)	 $result_daily_ins=mysqli_query($connect,$query_daily_ins); 


																								   if(! $result_daily_ins )  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! </font><br><br>"   ; 


													                                                   #    if($test_No)
																											   echo "$ttn :: $query_daily_ins <br><br>";


											  #		echo $result_ins."<br>";

				 }


		}  # end of foreach

  if($test_No==4) exit;



  echo "<html>
  <body>
  $style_css
  
  	<form method=post action='$cur_php' enctype='multipart/form-data' name=myform>	
    <input type='hidden' name=mode value='thema_daily_story_up'>	
    <input type='hidden' name=thema_story[up_day] value='$up_day[0]'>	
  
  <table class=n1>

   <tr valign=top><Td width=700 colspan=2> <a href='$cur_php?mode=news_read&key_word=\"특징주\"&max_pages=1'>종목검색</a> </td></tr>

   <tr class=tt4 align=center><td>NO</td> <td>테마명</td><td>테마내용</td></tr> ";
              

	 # 4) 시작	: 당일 테마에 대한 스토리 요약, 보여주기


		 foreach($today_thema_info as $thema_key => $thema_name) {

			  if(empty($thema_name)) continue;

         # 테마명으로 테마 찾기
          
            echo "<tr><td>$thema_key</td> <td>$thema_name</td>
			
						<td>                         
						<textarea name='thema_story[$thema_key]' id=\"mcts\" style=\"width:680px; height:88px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc $auto_clear_tag ></textarea>
						
						</td>
						
						</tr> ";



		 }


 echo "<tr><td colspan=5> <input type=submit value='등록' class=form_nc style='width:60px;'>     </td></tr>";
              

echo "</form></body></html>";


 		 # 4) 끝	: 당일 테마에 대한 스토리 요약



#################################################################
} # end of thema_file_update 
#################################################################



#################################################################
function thema_daily_story_update ($adminID,$thema_story,$connect)  {
#################################################################
global $cur_php;

$test_No=1;
   
        $up_day=$thema_story['up_day'];


		 foreach($thema_story as $thema_no => $thema_story_cts) {

                    if($thema_no=='up_day') continue;
                    if($thema_story_cts=='') continue;


						$thema_story_cts=addslashes($thema_story_cts); ## 따옴표가 있으면.. 슬래시를 붙여줌

							 									$query_ins="insert into tbl_daily_thema_story set thema_no='$thema_no',thema_story='$thema_story_cts',uDate='$up_day'";
																$result_ins=mysqli_query($connect,$query_ins); 
																
                                                          if(! $result_ins )  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! <br><br>"   ; 

												   		echo $query_ins."<br>";

		 }









exit;




echo "
<html>
    <meta http-equiv=\"refresh\" content=\"5;url=$cur_php?mode=news_read&key_word=특징주\">
</html>
     ";

echo " 등록이 완료되었습니다.";

#################################################################
} # thema_daily_story_update ($adminID,$thema,$connect)
#################################################################




?>