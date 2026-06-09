<?php

# DB연결
require "./env/cnt.inc";

# error 표시
 error_reporting( E_ALL& ~E_NOTICE ); # ~E_NOTICE
 ini_set( "display_errors", 1 );

#변수정의
$mode = $_REQUEST["mode"];
$admin_info=($_COOKIE['opt']);


if(!$mode) $mode='write';
$cur_php = basename($_SERVER['PHP_SELF']);


#변수정의

if($mode=='prj_list')            { prj_lisT($connect); }
elseif($mode=='prj_write')                {  prj_writE ($connect); }
elseif($mode=='prj_update')    {  prj_updatE($connect); }

elseif($mode=='prj_view')              { prj_vieW($connect); }

elseif($mode=='if')              { prj_iFrame(); }

elseif($mode=='analysis_insert' or $mode=='asli')                { analysis_stock_list_InserE($connect); }
elseif($mode=='analysis_insert_update' or $mode=='asl_u')    { analysis_stock_list_updatE($connect); }

elseif($mode=='asl_pu')    { analysis_stock_list_price_updatE($connect); }


elseif($mode=='analysis_view')                {  analysis_vieW($connect); }
elseif($mode=='analysis_view_write')                {  analysis_view_writE($connect); }
elseif($mode=='analysis_view_update')    { analysis_view_updatE($connect); }

elseif($mode=='ashv')    { analysis_stock_history_vieW($connect); }

elseif($mode=='ashv_all')   analysis_stock_history_view_aLL($connect);


elseif($mode=='trade_review_list' or $mode=='trl')  trade_review_lisT($connect);
elseif($mode=='trade_today_history_all_insert' or $mode=='tthai')  trade_today_history_all_insert($connect); ## 키움 거래내역을 일괄적으로 올림.

elseif($mode=='trade_daily_list' or $mode=='tdl')  trade_daily_lisT($connect);
elseif($mode=='trade_daily_write' or $mode=='tdw')  trade_daily_writE($connect);
elseif($mode=='trade_daily_view' or $mode=='tdv')  trade_daily_vieW($connect);
elseif($mode=='trade_daily_update' or $mode=='tdu')  trade_daily_updatE($connect);

elseif($mode=='trcl')  trade_reason_cmt_lisT($connect);

elseif($mode=='tru')  trade_reason_cmt_UpdatE($connect);

elseif($mode=='cm') category_manage($connect); ##카테고리 관리

elseif($mode=='img_pop')                {  img_vieW($connect); }


else  {  echo "<script language=\"javascript\">
    			alert(\" Version : $ver \");
    			</script>    			
    			";			
		}

mysqli_close($connect);



############################################
function prj_writE($connect) {
###########################################
require "./env/e.fnc";
require "./env/inf.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$prj_no=$GR_Vals['prj_no'];

$default_cts="1) 전략개요<br><br><br><br>
2) 검색식<br><br><br><br>
3) 샘플 종목<br><Br><br><br>
";
  
  
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

  	   $prj_type_array=array("5회 분석","1회 분석");
 
 
  if($prj_no ) {

								  $arr_qry['qry']="select * from tbl_prj_yehior where prj_no='$prj_no' ";
								  $result=php_mysql_Query($arr_qry,$connect);     

								  $prj_value=$result['value'][0];
								
								  # $rtime=calender_str(1,0,$value['rtime']);
									#$yy=date("y",$value['rtime']);

								  # 첨부파일 불러오기
								#  $attach_file_tag=attach_file_Display($value['attach_file'],$value['no'],1,0);

								$default_cts=$prj_value['contents'];

						       $today_ptime=calender_str(1,1,strtotime($prj_value['uDate']));


							    if($prj_value['off_on']) $off_on_chk_str="checked";






	  }

	  else { 

				 $today_ptime=calender_str(1,0,time());
                

          	  }

				$rtime=$today_ptime['unix_str'];


				     	for($tt=0;$tt<count($prj_type_array);$tt++) {

												 if($prj_value['prj_type']==$tt) $chk_str="checked";
												 else $chk_str="";

												 $prj_type_str.="<input type=radio name='prj_type' value='".$tt."' $chk_str>".$prj_type_array[$tt]."";
							}
 




$prj_off_on_str="<input type=checkbox name='off_on' $off_on_chk_str>완료";




#$cycle_type_tags="<tr><td><input type=\"hidden\" name=\"cycle_type\" value=\"0\"></td></tr>"; 



  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>GR_Vals</title>
    
			 $style_css



	<script language=\"javascript\">
     
			 
			 function      chkfrm(f) {	         
				
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
						 


if(f.off_on.checked ==true)	f.off_on.value=1;

else { 
	f.off_on.checked=1;
	f.off_on.value=0;   
  //  alert(f.off_on.value);
}

    f.submit();	
	
      }

	 </script>



	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['i3t'].">

        <table width=".$tbl_width['i3t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


        <tr>
  
		            <td valign=top > 

		";

# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		

		
		<table width=100%  border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"prj_update\">
		<input type=\"hidden\" name=\"prj_no\" value=\"".$prj_no."\">

		       <tr><td colspan=2><a href='$cur_php?mode=prj_list'>list</a> | <a href='javascript:window.location.reload();'>새로고침</a></td></tr>


			   <tr align=\"LEFT\" valign=\"MIDDLE\">
				  <td align=left style='padding-top:5px;font-size:12px;' nowrap>

					 <img src='../img/ic/ic_pen02.gif'> </font>매매전략
				  </td>
				  <td colspan=3>	            
					  <input type=text size='80' name='title' id='title' class=form_nc value=\"".$prj_value['title']."\">

					  $prj_off_on_str


				 </tr>



	  			   <tr align=\"LEFT\" valign=\"MIDDLE\">
				  <td align=left style='padding-top:5px;font-size:12px;' nowrap>

					 <img src='../img/ic/ic_pen02.gif'> </font>버젼
				  </td>
				  <td colspan=3>	            
					  <input type=text size='20' name='ver' id='id_version' class=form_nc value=\"".$prj_value['ver']."\">

					  $prj_type_str
				 </tr>




				 <tr align=\"left\">
				 <td></td>

					 <td align='left' style='padding-top:15px;' colspan=4>     
					<img src='../img/ic/6.gif' title='시작일'>
			     <input type='text' name='rtime'  id='rtime' value='$rtime' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime','','0')\" style='cursor:hand'>

				<input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>                    
								 <img src='../img/cafe_unlock.gif' title='* 비밀번호를 입력하지 않으면 글을 수정하거나 삭제하실 수 없습니다.'>
								 <input type=\"Password\" name=\"usrpwd\" value=\"".$prj_value['usrpwd']."\" size=\"15\" maxlength='8' class=form_nc>          
								 $calender_js
					 </td>
					 
					</tr>
					 

");



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

//  $base_year=date("y");
//  $up_Base_Dir="../dta/prj/$base_year"; 


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





echo "
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";
echo "</tR></table>";

echo "</body></html>";


 ################### end of write_form #######################
}
################### end of write_form #######################





################### start of prj_update #######################
 function  prj_lisT($connect) { 
################### start of prj_update #######################
global $admin_info;
global $cur_php;

require "./env/inf.fnc";
require "./env/e.fnc";


get_Permit($admin_info,"$cur_php?mode=prj_list");

 $prj_type_array=array("5회","");


  $today = date("Y-m-d");




$arr_report['qry']="SELECT * FROM `tbl_prj_yehior`  order by off_on,prj_type,uDate desc ";  # limit 0,30
$get_prj_news=php_mysql_Query($arr_report,$connect);

  echo $style_css;

   echo "<table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98% >";

   echo "<tr><td colspan=4> &nbsp; <img src='../img/pen.gif'> <a href='$cur_php?mode=prj_write'>전략 등록</a></td></tr>";

   echo "<tr  align=center bgcolor=yellow height=40><td>No</td><td>Type</td><td width=300>제목</td><td>등록수</td><td>chkUpdate</td><td>수정</td></tr>";

  foreach($get_prj_news['value' ] as $no => $prj_news) {

	     $nr++;

  //	 $up_day=calender_str(3,151,$prj_news['uDate']);
		 $up_day=date_str(13,1,strtotime($prj_news['uDate'])); # 날짜를 스트링으로 표시


## 등록된 종목리뷰 갯수
	 $arr_analysis['qry']="SELECT no,uDate FROM `tbl_prj_analysis`  where prj_no='".$prj_news['prj_no']."'";  # limit 0,30
   $get_prj_analysis=php_mysql_Query($arr_analysis,$connect);

	$count_nums=count($get_prj_analysis['value']);


    $chk_up_nums="";

	 if($prj_news['prj_type']==0)  # 날짜별로 등록해야 하는 게시물인 경우 오늘날짜를 검색해서, 리스트에 표시해줌
					  {
						  $count_nums=$count_nums/5;

						# 오늘 등록할 데이타가 있는지 체크
						 $arr['qry']="SELECT uDate FROM `tbl_prj_analysis`  where prj_no='".$prj_news['prj_no']."'  and  uDate='".$today."' ";  # limit 0,30
						 $get_prj_Date=php_mysql_Query($arr,$connect);

						 if($get_prj_Date['value']) $chk_up_nums=$today." <font style='color:red;font-weight:bold;'>(".count($get_prj_Date['value']).")";
						
					  }



       if($prj_news['off_on']) $font_tags="style='color:#BDBDBD;font-size:12px;' ";


     
       echo "<tr height=40 align=center $font_tags>";
	   
	    if($prj_news['off_on']) 	   echo "<td >완료</td>";
	                                   else            echo "<td >$nr</td>";

       echo "<td>". $prj_type_array[$prj_news['prj_type']]."</td>";

	   echo  "<td align=left ><a href='$cur_php?mode=prj_view&prj_no=".$prj_news['prj_no']." ' target='prj_u2' $font_tags>".$prj_news['title']."</td><td>$count_nums</td>";

	   #<td>".$prj_news['prj_type']." </td><td>".$prj_news['ver']."</td>

	   echo "<td>".$chk_up_nums."</td>";

	   echo "<td><a href='$cur_php?mode=prj_write&prj_no=".$prj_news['prj_no']." '><img src='../img/memo_open.gif'></a></td>";
	   
	   echo "</tr>";


	   

  }

echo "</table>";


exit;


 ################### end of prj_update_form #######################
}
################### end of prj_update_form #######################


################### start of prj_vieW #######################
 function  prj_vieW($connect) { 
################### start of prj_vieW #######################
global $cur_php;

require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');


$arr_report['qry']="SELECT * FROM `tbl_prj_yehior`  where prj_no='".$GR_Vals['prj_no']."' ";  # limit 0,30
$get_prj_news=php_mysql_Query($arr_report,$connect);

 $prj_value=$get_prj_news['value'][0];
 $contents= $prj_value['contents'];

$today= date("Y-m-d");     


# 컨텐츠에서 이미지 추출하기
$max_width=$tbl_width['prj_u2']*0.95;
#echo $max_width;
#$urls="$cur_php?mode=prj_view_img&prj_no=".$prj_value['prj_no']."";

$urls="$cur_php?mode=img_pop&type=prj&prj_no=".$prj_value['prj_no']."";
$contents=base64_Img_decode($urls,$contents,$max_width);

#print_r($arr_report);

echo "<html><body>";

echo $style_css;


#$aa = getimagesizefromstring($prj_value['contents']);
#print_r($aa);


   
  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>


        <head>
             <title>report</title>    
			 $style_css			 
		</script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\"  onLoad='document.myform.title.focus();'>";



echo "        <table width=".$tbl_width['prj_u2']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  ><tr><td> ";  ## start of table 000 


       echo "<table    style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -001


	    echo "<Tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:20px;color:blue; height:40px;' align=center ><td></td><td> ".$prj_value['title']."  </td></tr>";

       echo "<Tr><td colspan=2>".$contents."</td></tr>";

      echo "</table>";    ## end of table 000 -001

	  echo "</td></tR>";



	  echo "<tr><td>";  ## 하단 모니터링 리스트 

	   echo "<table border=0   style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -002


if(empty($prj_value['prj_type'])) {  #  검색식에서 나온 종목을 일괄 등록후 일정 기간 동안 모니터링 하는 방식

																					   echo "<tr><td style='font-size:13px;' colspan=2> <img src='../img/pen.gif'><a href='$cur_php?mode=asli&prj_no=". $prj_value['prj_no']." '>리스트 등록</td></tr>";


																													 ###  analysis List

																													 $arr_analysis['qry']="SELECT as_no,uDate,review_On FROM `tbl_prj_analysis`  where prj_no='".$GR_Vals['prj_no']."'  order by as_no desc , no   ";  # limit 0,30

																													 $arr_analysis['keys']='as_no';
																													 $arr_analysis['multi_keys']=1;
																													 $get_prj_analysis=php_mysql_Query($arr_analysis,$connect);

																													 #print_r($get_prj_analysis['multi_keys']);

																												#var_dump($get_prj_analysis['multi_keys']);

																													$nn=1;

																												 if(empty($get_prj_analysis['multi_keys'])) exit;


																													 foreach($get_prj_analysis['multi_keys'] as $as_key => $as_value) {

																														  echo "<tr style='font-size:13px;'><td width=40 align=center>$nn</td>";

																																	 foreach($as_value as $av_key => $av_value) {
																																			   $chk_days="";
																																				   $up_day=calender_str(3,13,$av_value['uDate']);

																																				   if($av_value['review_On']) $img_on="<img src='../img/ic/check_on.gif'>  "; 

																																				   else {  $img_on="<img src='../img/ic/check_off.gif'>  ";

																																								 if($today>=$av_value['uDate']) $chk_days="<font style='color:red;font-weight:bold;'>";										 							

																																				   }
																																			   

																																				   if($av_value['uDate']==$today) $up_day['unix_str']="<font style='color:red;font-weight:bold;'>Today</font>";


																																				
																																					   echo "<td><a href='$cur_php?mode=analysis_view&as_no=".$av_value['as_no']."'>$img_on $chk_days".$up_day['unix_str']."</a></td>";		          

																																	 }

																														   echo "</tr>";
																																 $nn++;
																														 }

}



else  {  # start of 1회성 분석 게시판인 경우 하단에 별도 표시

											   if($GR_Vals['opt']=='write'){ # start of 쓰기모드


																											  echo "<tr><td>";
																																	  sub_analysis_view_writE($GR_Vals,$connect);
																											   echo "</td></tr>";
											   } # end of 쓰기 모드

											   
											  elseif($GR_Vals['opt']=='update'){ # start of 쓰기모드

														   sub_analysis_view_updatE($GR_Vals,$connect);

											   }



										  else { # 리스트보기
										  
																				echo "<tr><td style='font-size:13px;'> <img src='../img/pen.gif'><a href='$cur_php?mode=prj_view&prj_no=". $prj_value['prj_no']."&opt=write'>등록</td></tr>";


																																			 $arr_analysis_stock['qry']="SELECT * FROM `tbl_prj_analysis`  where prj_no='".$GR_Vals['prj_no']."'  order by no desc"  ;  # limit 0,30
																																			 $get_analysis_stock=php_mysql_Query($arr_analysis_stock,$connect);


																																				  ###### start of if																														
																																						 if($get_analysis_stock['value']) {
																																																				  
																																																									  # start of foreach 001
																																																											 foreach($get_analysis_stock['value'] as $ga_key => $ga_value) {

																																																																 $base_day_str= calender_str(3,13,$ga_value['uDate']);

																																																																		 $max_width=50;
																																																																		$urls="$cur_php?mode=img_pop&type=analysis&no=".$ga_value['no']."";
																																																																		$contents=base64_Img_decode($urls,$ga_value['contents'],$max_width);

																																																																 echo "<tr>";

																																																																  echo "<td style='font-size:13px;' width=90 align=center>".$base_day_str['unix_str']."</td>";

																																																																 echo "<td >".$ga_value['title']."</td>";

																																																																 echo "<td nowrap>".$contents."</td>";
																																																																  echo "</tr>";
																																																												 } 
																																																											# end of foreach 001

																																																				

																																																	 
																																		

																																								  } 
																																				  #### end of if

														 }  		  #### end of 리스트보기




} #  end of 하단 


	   echo "</table>";  ## end of table 000 -002

	  echo "</td></tr>";  # 하단 모니터링 리스트



echo "</td><tr></table>";  # end of table 000 

echo "</body></html>";



exit;



 ################### end of prj_vieW #######################
}
################### end of prj_vieW #######################



############################################
function sub_analysis_view_writE($GR_Vals,$connect) {
###########################################

$test_on=0;

if($test_on) print_r($GR_Vals);

    # 변수 할당

$today= date("Y-m-d");   
# 	$rtime=$today_ptime['unix_str'];
	
		
  # 번호가 있다면.. 게시물 내용을 불러올 것	 
   
  echo "    
			 	<script language=\"javascript\">
     
			 
			 function      chkfrm(f) {	         
				
     	  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
          contents= document.getElementById('ir1').value;
		
	if (contents== '<p>&nbsp;</p>' || contents== '') {
		alert('내용을 입력하여 주십시오');
		return false;
	}
						 

    f.submit();	
	
      }

	 </script>



	";


# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  

		<table width=100%  border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"prj_view\">
        <input type=\"hidden\" name=\"prj_no\" value=\"".$GR_Vals['prj_no']."\">
        <input type=\"hidden\" name=\"opt\" value=\"update\">
		
		

				 <tr align=\"left\">
					 <td align='left' style='padding-top:15px;' colspan=4>     

				    					<img src='../img/ic/6.gif' title='시작일'>
										
			     <input type='text' name='uDate'  id='rtime' value='$today' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime','','0')\" style='cursor:hand'>
				 <input type=text size='80' name='title' id='title' class=form_nc >
				 <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;cursor:hand;'>                    							
				 
								
					 </td>
					 
					</tr>
						
					 
");

	echo ("

   <tr>
		<td colspan=4>				
		 <script language=\"javascript\" src=\"../env/js/calender.js\" ></script>
  ");

				# 시작 :스마트 에디터 불러오기
				echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
				echo"<textarea name=contents id=\"ir1\" style=\"width:755px; height:500px; display:none;\">$default_cts</textarea>";

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


$tbl_insert_cts="<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px dashed #c7c7c7; border-left:0; border-bottom:0;\" attr_no_border_tbl=\"1\" class=\"__se_tbl\">
<tr><td style=\"border:1px dashed #c7c7c7; border-top:0; border-right:0; background-color:#ffffff\" width=\"30\">&nbsp;
</td><td style=\"border:1px dashed #c7c7c7; border-top:0; border-right:0; background-color:#ffffff\" width=\"600\"></td></tr>
</table>";
 
$tbl_insert_cts=rawurlencode($tbl_insert_cts);  # 자바스크립트에 변수 넘겨준후 다시 자바스크립트에서 decode 할때  호환되게 하려면.. 


echo "  
   <tr class=n1 align=\"CENTER\" valign=\"MIDDLE\">
   <td colspan=4>
   <br>
    <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>
    </form>
 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 

echo "</td>
      </tr>";

echo "
      </table>  <!-- start of table 000 -->
      ";


 ################### end of sub_analysis_view_writE #######################
}
################### end of sub_analysis_view_writE #######################



################### start of analysis_update #######################
 function sub_analysis_view_updatE($GR_Vals,$connect) { 
################### start of analysis_update #######################

$test_on=0;

# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$GR_Vals['contents']=addslashes($GR_Vals['contents']);

#$today= date("Y-m-d");   

	$up_day=explode(" ",$GR_Vals['uDate'])[0];

if($test_on) print_r($GR_Vals);
	
$up_qry = "insert into tbl_prj_analysis set  prj_no='".$GR_Vals['prj_no']."',uDate='".$up_day."',title='".$GR_Vals['title']."', contents='".$GR_Vals['contents']."'";         

  if(empty($test_on)) 	$result_as_no=mysqli_query($connect,$up_qry); 
  else echo $up_qry."<Br>";

$fwd_urls="$cur_php?mode=prj_view&prj_no=".$GR_Vals['prj_no']."";

  if(empty($test_on))  echo "	  <meta http-equiv=\"refresh\" content=\"0;url=$fwd_urls\"> ";
  else echo $fwd_urls;



    mysqli_close($connect);
exit;




 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################








################### start of prj_update #######################
 function  prj_updatE($connect) { 
################### start of prj_update #######################

require "./env/e.fnc";

$test_on=0;


#시작: 변수정의
	$up_qry="";

# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');

#	$rtime=time();
# 끝: 변수정의


if($test_on) print_r($GR_Vals);


$up_day=explode(" ",$GR_Vals['rtime']);


#첨부파일 관리
#$GR_Vals['attach_file']=attach_file_mng($GR_Vals['upfile'],$GR_Vals['upfile_chk'],$GR_Vals['upfile_chk_hidden'],$GR_Vals['upfile_old_display_name'],$GR_Vals['upfile_new_display_name'],$GR_Vals['pims_no']);
#$GR_Vals['contents']= preg_replace("/tempUpFile/", "".$GR_Vals['no']."",$GR_Vals['contents'],-1,$count); # 임시파일명이 발견된다면

$GR_Vals['contents']=addslashes($GR_Vals['contents']);
$skip_Array=array('upfile','upfile_chk','upfile_chk_hidden','upfile_old_display_name','upfile_new_display_name','upfile','rtime','no','uDate','tags');


# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($GR_Vals as $prj_key => $prj_value) { # start of for

					  if(in_array($prj_key,$skip_Array)) continue;

								  #if($pims_key == 'cycle_data') $pims_value=trim($pims_value);
								 #  if($pims_value=="") $pims_value=0;

								  $up_qry.="$prj_key='$prj_value',";
} # end of for

$up_qry=substr($up_qry,0,-1);

#$up_qry.="tags='".$GR_Vals['tags']."'";

# 끝: 받은 자료를 가지고 쿼리로 만듬



#echo $up_qry;
#exit;


if($GR_Vals['prj_no']) {

	$query_ins="update tbl_prj_yehior set   $up_qry where prj_no='".$GR_Vals['prj_no']."'   ";
}

	else {
	$query_ins="insert into tbl_prj_yehior set $up_qry";

	}




if($test_on) { echo $query_ins;
                           exit;
                        }

else  $result_ins=mysqli_query($connect,$query_ins); 





if($result_ins) {
			 Header("Location:$cur_php?mode=prj_list");
		     
	        }

				else  echo "error";
 

#       mysqli_close($connect);





exit;







 ################### end of prj_update_form #######################
}
################### end of prj_update_form #######################



############################################
function  prj_iFrame() {
###########################################
global $cur_php;
global $tbl_width;
require "./env/e.fnc";
require "./env/inf.fnc";
$GR_Vals=Get_Vals('mode');


#print_r($GR_Vals);


if($GR_Vals['type']=='trl') { $call_urls="$cur_php?mode=trl"; }
else $call_urls="$cur_php?mode=prj_list"; 


echo"<html><body>";

echo "

		   <script type=\"text/javascript\">
										
		
																function      calcHeight(frame_id) {
																	var the_height =document.getElementById(frame_id).contentWindow.document.body.scrollHeight;
																	document.getElementById(frame_id).height = the_height;
																	document.getElementById(frame_id).style.overflow = \"hidden\";

//alert(frame_id);
														         //    alert(the_height);


																}

																</script>

";



$tbl_prj_u2=$tbl_width['prj_u2']+50;

echo "

  	<table height=100% width=100%>
   
						   <tr valign=top>
													<Td width='".$tbl_width['prj_u2']."' >

																<iframe src='$call_urls' id='res_frame_u1'    frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_u1' ></iframe>

													</td>

												<Td width='".$tbl_prj_u2."' >

													<iframe src='' id='res_frame_u2'    frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_u2' ></iframe>

														
													</td>


												<Td width='".$tbl_width['i3t']."'  valign=top>

																<iframe src='' id='res_frame_u3'   frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_u3' ></iframe>

													</td>

												
													
													 
													 <td width='".$tbl_width['tdw']."'>
																	<iframe src='$cur_php?mode=trcl' id='res_frame_u4'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_u4' ></iframe>
												</td>

												
											
								</tr>

 
								
							";

			echo "</table>";


										echo "</body></html>";



 ################### end of  prj_iFrame() #######################
}
################### end of  prj_iFrame() #######################


############################################
function analysis_stock_list_InserE($connect) {
###########################################
require "./env/e.fnc";
require "./env/inf.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$prj_no=$GR_Vals['prj_no'];
$as_no=$GR_Vals['as_no'];

    # 변수 할당

 
  if($as_no ) {

								  $arr_qry['qry']="select * from tbl_prj_analysis where as_no='$as_no' ";
								  $result=php_mysql_Query($arr_qry,$connect);     

								  $as_value=$result['value'][0];
								
								  # $rtime=calender_str(1,0,$value['rtime']);
									#$yy=date("y",$value['rtime']);

								  # 첨부파일 불러오기
								#  $attach_file_tag=attach_file_Display($value['attach_file'],$value['no'],1,0);

								$default_cts=$as_value['contents'];

					     	 $today_ptime=calender_str(1,1,strtotime($as_value['uDate']));

						  #   $get_uDate_time=Get_analysis_Date($as_value['uDate']);



	  }

	  else { 

			       	    $today_ptime=calender_str(1,0,time());
						$add_day=array(1,5,10,30);
				        $get_uDate_time=Get_analysis_Date(time(),$add_day);

				        $uDate_2th=$get_uDate_time[0];
				        $uDate_3th=$get_uDate_time[1];
						$uDate_4th=$get_uDate_time[2];
						$uDate_5th=$get_uDate_time[3];


						$uDate_Insert_Tags="<img src='../img/imoticon/num3/2.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_2' value='$uDate_2th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_2','','0')\" style='cursor:hand'>
				 					 <img src='../img/imoticon/num3/3.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_3' value='$uDate_3th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_3','','0')\" style='cursor:hand'>
				 					 <img src='../img/imoticon/num3/4.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_4' value='$uDate_4th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_4','','0')\" style='cursor:hand'>
				 					 <img src='../img/imoticon/num3/5.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_5' value='$uDate_5th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_5','','0')\" style='cursor:hand'>";

          	  }

				$rtime=$today_ptime['unix_str'];


  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css

	<script language=\"javascript\">
     
			 
			 function      chkfrm(f) {	         
				
		
	if (f.as_stock_info.value=='' ) {
		alert('내용을 입력하여 주십시오');
		return false;
	}
						 

    f.submit();	
	
      }

	 </script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['i3t'].">

        <table width=".$tbl_width['i3t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


        <tr>
  
		            <td valign=top > 

		";




# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		<table width=100%  border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"asl_u\">
		<input type=\"hidden\" name=\"prj_no\" value=\"".$prj_no."\">

		       <tr><td colspan=2><a href='$cur_php?mode=prj_view&prj_no=$prj_no'>list</a> | <a href='javascript:window.location.reload();'>새로고침</a></td></tr>


				 <tr align=\"left\">
					 <td align='left' style='padding-top:15px;' colspan=4>     
					 <img src='../img/imoticon/num3/1.gif' title='시작일'>
			     <input type='text' name='uDate[]'  id='rtime_1' value='$rtime' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_1','','0')\" style='cursor:hand'>

				 $uDate_Insert_Tags


				<input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;cursor:hand;'>                    							
				$calender_js
								
					 </td>
					 
					</tr>
					 
");



## 이미지를 불러오고 삽입하는 함수

 # 기본 이미지 사이즈는 insert_attach.php 에서 조정이 가능

echo "<tr><td colspan=4>분석 종목 ( 조건검색 > 복사 ) </td></tr>";
echo "<tr><td colspan=4><textarea name=as_stock_info style=\"width:755px; height:412px;\"></textarea></td></tr>";



  #  다운로드는 파일명.. 디렉토리 번호를 넘겨준후에.. 모달창에서 다운로드 받도록 함. 다운로드 할수 있도록 함.


echo "  


   <tr class=n1 align=\"CENTER\" valign=\"MIDDLE\">
   <td colspan=4>
   <br>
    <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>
    </form>
 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 



echo "</td>
      </tr>";





echo "
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";
echo "</tR></table>";

echo "</body></html>";


 ################### end of write_form #######################
}
################### end of write_form #######################


################### start of analysis_update #######################
 function analysis_stock_list_updatE($connect) { 
################### start of analysis_update #######################

require "./env/e.fnc";

#시작: 변수정의
	$up_qry="";

$test_no=0;

# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$GR_Vals['contents']=addslashes($GR_Vals['contents']);



				$query_as_no="select max(as_no) as as_no from tbl_prj_analysis";
				$result_as_no=mysqli_query($connect,$query_as_no);
				if($result_as_no) { $max_num = mysqli_fetch_array($result_as_no, MYSQLI_ASSOC);
                    				              $as_no=$max_num['as_no']+1;
                       				            }	  else $as_no=1;

#	$rtime=time();
# 끝: 변수정의

#print_r($GR_Vals);



$as_vals= preg_replace("/[#\&\+\-%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['as_stock_info']); # /\
$as_array=explode("\n",$as_vals);

$tot_num=count($as_array)-2;


#if($tot_num>=9) {

#    $max_num=9;

#	if($tot_num>=12) $max_num=12;

# 최대 갯수 제한없이 하는게 맞는듯.


if($tot_num<9) {

		  $alert_msg= "등록종목이 9개 미만입니다";

		  echo "
													<html>
													<body onload=\"alert('$alert_msg');window.history.back();\">											
													</html>
														 ";
		  exit;

	}


if($test_no) {

echo "<font color=red>$tot_num</font>";


	}





$base_uDate=explode(' ',$GR_Vals['uDate'][0])[0];

foreach($as_array as $aa_k => $aa_value){

	# if($nn>$max_num) continue;

	$nn++;

             $sc_array=explode("\t",$aa_value);

          if($aa_k==0) continue;
          if($sc_array[0]==0) continue;


			 # 0 종목코드 2 현재가 3 등락률 4 거래량 5 거래대금 6 시가총액

				 $stock_qry="insert into tbl_prj_analysis_stock set  as_no_root='1', prj_no='".$GR_Vals['prj_no']."', as_no='".$as_no."', as_uDate='".$base_uDate."', stock_code='".$sc_array[0]."'    ";

         if(empty($test_no)) 		$result_stock=mysqli_query($connect,$stock_qry); 
				else                                echo $stock_qry."<Br>";

}

foreach($GR_Vals['uDate'] as $u_k => $u_Date_Value) {





	$up_day=explode(" ",$u_Date_Value)[0];

    $GR_Vals['uDate'][$u_k]=$up_day;

	
    if($u_k==0)   {    $up_qry = "insert into tbl_prj_analysis set  prj_no='".$GR_Vals['prj_no']."',uDate='".$GR_Vals['uDate'][$u_k]."', uDate_On='1', as_no='".$as_no."', review_On='1' ,contents='".$GR_Vals['contents']."'";         }

					else {  

										  $up_qry = "insert into tbl_prj_analysis set  prj_no='".$GR_Vals['prj_no']."',uDate='".$GR_Vals['uDate'][$u_k]."', uDate_On='0', as_no='".$as_no."'";   
					
							 }

  if(empty($test_no)) 	$result_as_no=mysqli_query($connect,$up_qry); 
  else echo $up_qry."<Br>";

}

  if(empty($test_no))  echo "	  <meta http-equiv=\"refresh\" content=\"0;url=prj_yehior.php?mode=asl_pu&as_no=".$as_no."\"> ";
  # as_no 번호를 넘겨줘야, 리스트로 바로 이동함

    mysqli_close($connect);
exit;






 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################





################### start of analysis_update #######################
 function analysis_stock_list_price_updatE($connect) { 
################### start of analysis_update #######################
global  $cur_php;
require "./env/e.fnc";
$GR_Vals=Get_Vals('mode');

$test_on=0;


# 시세 기준 날짜 가져오기


				$query_uDate="select uDate  from all_stock_info order by uDate desc limit 0,1";

				$result_uDate=mysqli_query($connect,$query_uDate);

                $cur_day = mysqli_fetch_array($result_uDate, MYSQLI_ASSOC);

                $base_day= date("Y-m-d",strtotime($cur_day['uDate']));

				#  prj_analysis 에서 오늘 날짜와 관련된 것들을 찾아서 uDate_On으로 바꿔줌

              	$query_analysis['qry']="select *  from tbl_prj_analysis where uDate= '".$base_day."'";
				$get_analysis=php_mysql_Query($query_analysis,$connect);

foreach($get_analysis['value'] as $ga_key => $ga_value) { # start of ga_key

					         $qry['qry']="update  tbl_prj_analysis set uDate_On=1 where no= '".$ga_value['no']."' ";
							 $qry['result']=1;  # 실행만 원할때 

							 if(empty($test_on)) php_mysql_Query($qry,$connect); 
							 else echo $qry['qry']."<bR>";

							  #print_r($ga_value);

							  ## 해당 모니터링의 첫번째 데이타(as_no_root=1) 가져오기

							  $ga_qry['qry']="select * from  tbl_prj_analysis_stock where as_no= '".$ga_value['as_no']."' and  prj_no= '".$ga_value['prj_no']."' and as_no_root=1 ";
							  
							  $get_analysis_stock_array=php_mysql_Query($ga_qry,$connect);
							   
								  if($test_on) { echo $ga_qry['qry']."<br>"; 

								                              print_r($get_analysis_stock_array['value']);
								  
								                           }

                              # 당일 업데이트한 자료가 있다면.. 삭제할것

							  $ga_del['qry']="delete from  tbl_prj_analysis_stock where as_no= '".$ga_value['as_no']."' and  prj_no= '".$ga_value['prj_no']."' and as_uDate='".$base_day."' ";
							  $ga_del['result']=1;
							  
							  if(empty($test_on))  { 
								   php_mysql_Query($ga_del,$connect);
								    table_auto_increment('tbl_prj_analysis_stock ',$connect);

																					}
							  else echo $ga_del['qry']."<br>";

							  
							  foreach($get_analysis_stock_array['value'] as $gasa_key => $gasa_value) {

								      # 기준을 가져와서, 새로운 기준일에 맞춰서 

									  $all_stock['qry']="select * from all_stock_info where stock_code='".$gasa_value['stock_code']."' ";
									  $all_stock['keys']='stock_code';
									  $get_stock_info_array=php_mysql_Query($all_stock,$connect);
									  $get_stock_info= $get_stock_info_array['multi_keys'];

									  if($gasa_value['as_uDate']==$ga_value['uDate']) $as_no_root_str=", as_no_root=1"; else $as_no_root_str="";

									  #print_r($get_stock_info_array);
									  
									  $gasa_ins['qry'] = "insert into tbl_prj_analysis_stock set   as_no= '".$ga_value['as_no']."' , stock_memo= '".$gasa_value['stock_memo']."' ,  prj_no= '".$ga_value['prj_no']."'  ,  as_uDate= '".$ga_value['uDate']."' ,  stock_code= '".$gasa_value['stock_code']."'  ,  stock_price= '".$get_stock_info[$gasa_value['stock_code']]['stock_price']."' ,  stock_high_price= '".$get_stock_info[$gasa_value['stock_code']]['stock_high_price']."'   ,  stock_rate= '".$get_stock_info[$gasa_value['stock_code']]['stock_rate']."'  ,  stock_vol= '".$get_stock_info[$gasa_value['stock_code']]['stock_vol']."'  ,  stock_vol_cap= '".$get_stock_info[$gasa_value['stock_code']]['stock_vol_cap']."' ,  stock_cap= '".$get_stock_info[$gasa_value['stock_code']]['stock_cap']."'  $as_no_root_str ";
									  $gasa_ins['result']=1;

									 if($test_on)       print_r($gasa_ins);             
										else				   php_mysql_Query($gasa_ins,$connect);
																  
								} # end of foreach

    		#  prj_analysis 에서 오늘 날짜와 관련된 것들을 찾아서 uDate_On으로 바꿔줌

} # end of ga_key



##

              	$query_trade_review['qry']="select no,stock_code  from tbl_trade_review where DATE_FORMAT(uDate,'%Y-%m-%d')= '".$base_day."'";
				$get_trade_review=php_mysql_Query($query_trade_review,$connect);



	if($get_trade_review['value'] ) {		

																   foreach($get_trade_review['value'] as $gtr_key => $gtr_value) { # start of ga_key

																						  $get_stock_info=get_stock_info($gtr_value['stock_code'],$connect);

																						 $qry['qry']="update  tbl_trade_review set  stock_high_price= '".$get_stock_info['stock_high_price']."'   ,  stock_rate= '".$get_stock_info['stock_rate']."'  ,  stock_vol= '".$get_stock_info['stock_vol']."'  ,  stock_vol_cap= '".$get_stock_info['stock_vol_cap']."' ,  stock_cap= '".$get_stock_info['stock_cap']."' where no= '".$gtr_value['no']."' ";
																						 $qry['result']=1;  # 실행만 원할때 

																						 if(empty($test_on)) php_mysql_Query($qry,$connect); 
																						 else print_r($qry['qry']);
																   } # end of ga_key


	   }




 if(empty($test_on)) 	{    


	   if($GR_Vals['as_no']) Header("Location:$cur_php?mode=analysis_view&as_no=".$GR_Vals['as_no']."");
					else {
							 $alert_msg=" analysis_stock_list_price_updatE 시세 업데이트가 완료되었습니다.";

							 echo "
																		<html>
																		<body onload=\"alert('$alert_msg');window.history.back();\">											
																	</html>
														 ";
					}

						

	 } 



  mysqli_close($connect);


exit;





 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################



################### start of prj_vieW #######################
 function analysis_vieW($connect) { 
################### start of prj_vieW #######################
global $cur_php;

require "./env/inf.fnc";
require "./env/e.fnc";

$today = time();

$GR_Vals=Get_Vals('mode');

$test_on=0;

if($test_on) print_r($GR_Vals);



                     # 시작 :전체 종목 명 가져오기
																										   $arr_stock_srch['qry']="select stock_code,stock_name from all_stock_info";																											
																										   $arr_stock_srch['keys'] ='stock_code';
																										  # $arr_stock_srch['multi_keys'] =0;
 
																											$stock_srch_array=php_mysql_Query($arr_stock_srch,$connect);

                                                                                                          # 종목 네임 배열
																											$all_stock_name=$stock_srch_array['multi_keys'];

																											#print_r($all_stock_name);
                     # 끝 :전체 종목명 가져오기




if($GR_Vals['no']) {   
$arr_as_up['qry']="update `tbl_prj_analysis_stock` set  up_dn='".$GR_Vals['up_dn']." 'where no='".$GR_Vals['no']."'  "; 
$arr_as_up['result']=1;

					if(0) {  print_r($arr_as_up);  exit;}
						else 	php_mysql_Query($arr_as_up,$connect);
}


if($GR_Vals['stock_memo']) {   

$stock_memo=str_replace("'",'',$GR_Vals['stock_memo']);
 

$arr_as_up['qry']="update `tbl_prj_analysis_stock` set  stock_memo='".$stock_memo." 'where as_no='".$GR_Vals['as_no']."' and   stock_code='".$GR_Vals['stock_code']."'"; 
$arr_as_up['result']=1;

					if($test_on) {  print_r($arr_as_up);  exit;}
						else 	php_mysql_Query($arr_as_up,$connect);
}




$arr_as_no['qry']="SELECT * FROM `tbl_prj_analysis`  where as_no='".$GR_Vals['as_no']."' ";  # limit 0,30
$get_as_no=php_mysql_Query($arr_as_no,$connect);



#  $cts_tags="";
  
						 $cts_tags="<table width=100%>";

								foreach($get_as_no['value'] as $ga_key => $ga_value) {   # start of foreach

												 # var_dump($ga_value);

												 #echo $ga_key;

												   $up_day=calender_str(3,13,$ga_value['uDate']);

												 #	print_r($up_day);
												 $max_width=$tbl_width['make_thema_cts'];

												 
												 
												$contents=$ga_value['contents'];
												  $urls="$cur_php?mode=img_pop&type=analysis&no=".$ga_value['no']."";
												  $contents=base64_Img_decode($urls,$contents,$max_width);

											
												 if( $today>=strtotime($ga_value['uDate']) ) $analysis_view_write_tags="<a href='$cur_php?mode=analysis_view_write&no=".$ga_value['no']."' >";
												 else $analysis_view_write_tags="";

												   $arr_as_stock['qry']="SELECT * FROM `tbl_prj_analysis_stock`  where as_no='".$ga_value['as_no']."'  and as_uDate='".$ga_value['uDate']."'  ";  # limit 0,30
												   $get_as_stock=php_mysql_Query($arr_as_stock,$connect);

												    if($get_as_stock['value'])  $tot_num=" (".count($get_as_stock['value']).")";
													else $tot_num="";
													
												   $cts_tags.="<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:20px;color:blue;' align=center  height='49px;' width=100%><td><a name='#".$ga_value['no']."'></a>$analysis_view_write_tags".$up_day['unix_str']."</a>$tot_num</td></tr>";



												    if($get_as_stock['value']) {


														
																			$cts_tags.="<tr><td align=center><table style='border: 1px dashed orange; border-radius: 7px; background-color:#EFF2FB; border-spacing:3px;font-size:14px;' width=95%>";

																			 if($get_as_stock['value'][0]['as_no_root']) { $cts_tags.="<tr style='background-color:EFFBF8;' align=center><td colspan=2>종목명</td><td>등락률</td><td>거래량</td><td>거래대금</td><td >회전률</td><td>시총</td><td>메모</td><td></td></tr>";  $as_no_root=1;}
																			 else  { $cts_tags.="<tr style='background-color:EFFBF8' align=center><td colspan=2>종목명</td><td>전일비</td><td>등록이후</td><td>고가</td><td>거래량</td><td>%</td><td>거래대금</td><td>%</td><td>회전률</td><td>메모</td><td></td></tr>";  $as_no_root=0;}

																													 #var_dump($arr_as_stock);

																													foreach($get_as_stock['value'] as $as_k => $as_value) {

																												
																														 
																														 
													
																		
																														    


																														 #   print_r($get_as_stock['value']);																														 
																														 $up_img="";
																														      
                                                                                                                                         $stock_vol_tags=get_stock_vol_info($as_value['stock_code'],$as_value['as_uDate'],2,$connect);
																														 

																																						if($as_value['as_no_root']==1)$root_value[$as_value['stock_code']]=$as_value;


																																																							 #  if($as_k>11) break;

																																if($as_no_root==0) {  
																																
																																			   # 등록이후 + 이거나, 전일비 +5 또는 -5%인 경우에만 표시할 것

																																			   if($as_value['stock_price']<$root_value[$as_value['stock_code']]['stock_price'] and abs($as_value['stock_rate'])<5)  continue;																															

																																}

																																






																																						# (".deco_txt($sub_value['stock_rate'],131,0).",   ".deco_txt($sub_value['stock_vol']/10000,3,0)."만주, ,  <br>";


																																						if(empty($as_value['up_dn'])) $up_dn_img="<a href='$cur_php?mode=analysis_view&as_no=".$ga_value['as_no']."&no=".$as_value['no']."&up_dn=1'><img src='../img/bul_up_red.gif'></a> <a href='$cur_php?mode=analysis_view&as_no=".$ga_value['as_no']."&no=".$as_value['no']."&up_dn=-1'><img src='../img/bul_dn_blue.gif'></a>"; 
																																						
																																						else {   $up_dn_img=""; 

																																						             if($as_value['up_dn']==1) $up_img="<img src='../img/bul_up_red.gif'>"; 
																																									 elseif($as_value['up_dn']==-1)$up_img="<img src='../img/bul_dn_blue.gif'>";
																																						}


																																							if($as_no_root) {
																																								 $update_memo="<a  onclick=\"up_stock_memo('".$as_value['as_no']."','".$as_value['stock_code']."')\" style='cursor:hand;'><img src='../img/pen.gif'></a>";
																																							} else $update_memo="";



																																						$cts_tags.="<tr><td>$up_img</td>";
																																						$cts_tags.="<td rowspan=2 height=40><a href='daily_news.php?mode=top_pi_history&stock_code=".$as_value['stock_code']."' target='prj_u3'> ".$all_stock_name[$as_value['stock_code']]['stock_name']." $update_memo</td>";

																																						$cts_tags.="<td>".deco_txt($as_value['stock_rate'],131,0)." </td>";
																																						
																																							if($as_no_root==0) {

																																										$cts_tags.="<td align=left> ".deco_txt((($as_value['stock_price']/$root_value[$as_value['stock_code']]['stock_price'])-1)*100,121,'%')." </td>";

																																										$cts_tags.="<td align=left> ".deco_txt((($as_value['stock_high_price']/$root_value[$as_value['stock_code']]['stock_price'])-1)*100,121,'%')." </td>";
																																						}
																																						
																																					
																																						$cts_tags.="<td align=right>".deco_txt($as_value['stock_vol']/10000,3,0)."  만주</td>";

																																						if($as_no_root==0) {
																																								$cts_tags.="<td align=left> ".deco_txt((($as_value['stock_vol']/$root_value[$as_value['stock_code']]['stock_vol'])-1)*100,121,'%')." </td>";
																																						}

																																						$cts_tags.="<td align=right>".deco_txt($as_value['stock_vol_cap'],3,0)."억</td>";
																																						

																																						if($as_no_root==0) {

																																										$cts_tags.="<td align=left> ".deco_txt((($as_value['stock_vol_cap']/$root_value[$as_value['stock_code']]['stock_vol_cap'])-1)*100,121,'%')." </td>";
																																						}

																																						$cts_tags.="<td align=right>".deco_txt($as_value['stock_vol_cap']/$as_value['stock_cap']*100,132,30)."</td>";
																																																																												 
																																						
																																						if($as_no_root==0) {
																																								$cts_tags.="<td></td>";
																																						} else		$cts_tags.="<td align=right>".deco_txt($as_value['stock_cap'],3,0)."억</td>";

																																						
																																						$cts_tags.="<td>$up_dn_img</td>";
																														
																																						
																																						$cts_tags.="</tr>";

																																						$cts_tags.="<tr><td></td><td colspan=10>".$stock_vol_tags."</td></tr>";



																																						$cts_tags.="<tr><td></td><td colspan=10>".$as_value['stock_memo']."</td></tr>";

																																						$cts_tags.="<tr>$dot_line</tr>";

																													}

																			$cts_tags.="</table></td></tr>";




										


																}

																   $cts_tags.="<tr><td align=center>$contents</td></tr>";


								 } # end of foreach


                       

						$cts_tags.="</table>";

  
  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>


        <head>
             <title>report</title>    
			 $style_css			 
		</script>


				   <script type=\"text/javascript\">


	                   function      up_stock_memo(as_no,stock_code) {

                                                                                                               //						   alert(as_no);

						                                                                                                             get_stock_memo= prompt('메모를 등록하시겠습니까?');																																

																																
																																	 	 go_to_url_tags  ='$cur_php?mode=analysis_view&as_no='+as_no+'&stock_code='+stock_code+'&stock_memo=\''+get_stock_memo+'\'';																								

																																		//alert( go_to_url_tags);

                                                                                                                                           window.document.location.href=go_to_url_tags;

																									
																																	} // end of fnc ::: 



			</script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\"  onLoad='document.myform.title.focus();' >";


#  $tbl_u2_width=*0.95;

echo "        <table width=".$tbl_width['prj_u2']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  style='background-color:#ECF8E0;font-size:14px;'  >";

echo "<tr><td height='30px;'>&nbsp; <img src='../img/dot_r.gif'> <a href='$cur_php?mode=prj_view&prj_no=".$ga_value['prj_no']."'>List</a> :: analysis view</td></tr>";



echo "<tr><td align=center width=100%>   ";  ## start of table 000 

    

echo $cts_tags;




echo "</td><tr></table>";  # end of table 000 

echo "</body></html>";



exit;



 ################### end of prj_vieW #######################
}
################### end of prj_vieW #######################







############################################
function analysis_view_writE($connect) {
###########################################
require "./env/e.fnc";
require "./env/inf.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$no=$GR_Vals['no'];

$test_on=0;

if($test_on) print_r($GR_Vals);

    # 변수 할당


  if($no ) {
								  $arr_qry['qry']="select * from tbl_prj_analysis where no='$no' ";
								  $result=php_mysql_Query($arr_qry,$connect);     

								  $value=$result['value'][0];
								
								   #$rtime=calender_str(1,0,$value['rtime']);
								   #$yy=date("y",$value['rtime']);

								  # 첨부파일 불러오기
								#  $attach_file_tag=attach_file_Display($value['attach_file'],$value['no'],1,0);

														if($value['contents']) $default_cts=stripslashes($value['contents']);
														#else $default_cts="<br>일봉<br>";

  													    $today_ptime=calender_str(1,1,strtotime($value['uDate']));
								  
	  } 




	  	$rtime=$today_ptime['unix_str'];
	
		
  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css

	<script language=\"javascript\">
     
			 
			 function      chkfrm(f) {	         
				
     	  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
          contents= document.getElementById('ir1').value;
		
	if (contents== '<p>&nbsp;</p>' || contents== '') {
		alert('내용을 입력하여 주십시오');
		return false;
	}
						 

    f.submit();	
	
      }

	 </script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['i3t']." >

        <table width=".$tbl_width['i3t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


        <tr>
  
		            <td valign=top > 

		";


# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		<table width=100%  border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"analysis_view_update\">
        <input type=\"hidden\" name=\"no\" value=\"".$no."\">
        <input type=\"hidden\" name=\"as_no\" value=\"".$value['as_no']."\">
		
				 <tr align=\"left\">
					 <td align='left' style='padding-top:15px;' colspan=4>     
				     $rtime				<input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;cursor:hand;'>                    							
								
					 </td>
					 
					</tr>
					 
");

	echo ("

   <tr>
		<td colspan=4>								 
  ");

				# 시작 :스마트 에디터 불러오기
				echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
				echo"<textarea name=contents id=\"ir1\" style=\"width:755px; height:1512px; display:none;\">$default_cts</textarea>";

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
	 
	  </script>

";

//  $base_year=date("y");
//  $up_Base_Dir="../dta/prj/$base_year"; 


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





echo "
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";
echo "</tR></table>";

echo "</body></html>";


 ################### end of write_form #######################
}
################### end of write_form #######################









################### start of analysis_update #######################
 function analysis_view_updatE($connect) { 
################### start of analysis_update #######################

require "./env/e.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$GR_Vals['contents']=addslashes($GR_Vals['contents']);

$up_qry['qry'] = "update  tbl_prj_analysis  set  contents='".$GR_Vals['contents']."', review_On='1' where no='".$GR_Vals['no']."'  ";       
$up_qry['result']=1;  # 실행만 원할때 
php_mysql_Query($up_qry,$connect); 


#print_r($up_qry['qry']);

#if($result_ins) {
			 Header("Location:$cur_php?mode=analysis_view&as_no=".$GR_Vals['as_no']." ");
#window.open($go_to_url_tags, 'prj_u2')\"
#$go_to_url_tags  ="$cur_php?mode=analysis_view&as_no=".$GR_Vals['as_no']."";																								

		     
#	        }	else  echo "error";

			
       mysqli_close($connect);


exit;







 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################







################### start of prj_vieW #######################
 function analysis_stock_history_vieW($connect) { 
################### start of prj_vieW #######################
global $cur_php;

require "./env/inf.fnc";
require "./env/e.fnc";

$today = time();

$GR_Vals=Get_Vals('mode');

$test_on=0;




                                                                                                          # 종목 네임 배열
																											$get_stock_name=get_stock_info($GR_Vals['stock_code'],$connect);




## 매매전략에서 찾아오기
$arr_stock_history['qry']="SELECT * FROM `tbl_prj_analysis_stock`  where stock_code='".$GR_Vals['stock_code']."' and  as_no_root=1  order by no desc limit 0,5   ";  # limit 0,30
$get_stock_history=php_mysql_Query($arr_stock_history,$connect);


if($test_on) { 
	print_r($GR_Vals);
	print_r($get_stock_history); }



$max_width=$tbl_width['i4t']*0.97;

					
	 $cts_tags="<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px;'  width=$max_width  >";

						 $cts_tags.="<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:14px;color:blue;' align=center  height='49px;' ><td style='font-weight:bold;color:black;font-size:20px;' width=190px;>".$get_stock_name['stock_name']."</td><td>전략이름</td><td>상승률</td><td>고가상승률</td><td>거래량</td><td>거래대금</td><td>시가총액</td></tr>";


						if(!empty($get_stock_history['value'])) {
					
																											foreach($get_stock_history['value'] as $gsh_key => $gsh_value) {   # start of foreach

																												  $up_day=calender_str(3,13,$gsh_value['as_uDate']);												  
																												# 전략 가져오기
																												  $qry="select * from tbl_prj_yehior where prj_no='".$gsh_value['prj_no']."'";		
																												  $result=mysqli_query($connect, $qry); 
																												 $get_prj_info = mysqli_fetch_array($result);

																														
																																																									$prv_stock_price= ceil($gsh_value['stock_price'] /( 1+ $gsh_value['stock_rate']/100));

																																																									$stock_high_rate=(($gsh_value['stock_high_price']/$prv_stock_price)-1)*100;

																																																									if($stock_high_rate<$gsh_value['stock_rate']) $stock_high_rate=$gsh_value['stock_rate'];


																																																									if(!empty($gsh_value['stock_memo'])) {

																																																										$memo_span="rowspan=2";
																																																										$memo_tr="<tr style='font-size:13px; text-align:left;height:40px;'><td colspan=6><img src='../img/micon2.gif'> ".$gsh_value['stock_memo']."</td></tr>";

																																																									}  else																												
																																																										{ $memo_span=""; $memo_tr=""; }


																																
																																$cts_tags.="<tr style='font-size:15px; text-align:center;height:37px;'><td $memo_span>".$up_day['unix_str']."</td> <td>".$get_prj_info['title']."</td> <td>".deco_txt($gsh_value['stock_rate'],131,0)." </td> <td> ".deco_txt($stock_high_rate,121,'%')." </td> <td> ".deco_txt($gsh_value['stock_vol']/10000,133,300)."  만주</td> <td>".deco_txt($gsh_value['stock_vol_cap'],133,500)."  억 </td> <td>".deco_txt($gsh_value['stock_cap'],133,1000)."  억</td></tr>";

																																$cts_tags.= $memo_tr;
																																$cts_tags.="<tr>$dot_line</tr>";







																											 } # end of foreach

						}

                      

						$cts_tags.="</table>";

  
  # 번호가 있다면.. 게시물 내용을 불러올 것	 


echo $cts_tags;


exit;

 
 echo"<meta charset='utf-8'>";

  echo "<html>


        <head>
             <title>report</title>    
			 $style_css			 
		</script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\"  onLoad='document.myform.title.focus();' >";


#  $tbl_u2_width=*0.95;

echo "        <table width=".$max_width." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  style='background-color:#ECF8E0;font-size:14px;'  >";

echo "<tr><td height='30px;'>&nbsp; <img src='../img/dot_r.gif'> <a href='$cur_php?mode=prj_view&prj_no=".$ga_value['prj_no']."'>List</a> :: analysis view</td></tr>";



echo "<tr><td align=center width=100%>   ";  ## start of table 000 

    

echo $cts_tags;




echo "</td><tr></table>";  # end of table 000 

echo "</body></html>";


exit;


 ################### end of prj_vieW #######################
}
################### end of prj_vieW #######################





################### start of prj_vieW #######################
 function analysis_stock_history_view_aLL($connect) { 
################### start of prj_vieW #######################
global $cur_php;

require "./env/inf.fnc";
require "./env/e.fnc";

$today = time();

$GR_Vals=Get_Vals('mode');

$test_on=0;


if(empty($GR_Vals['prj_no'])) $GR_Vals['prj_no']=4; # 대량거래


## 매매전략에서 찾아오기
$arr_stock_history['qry']="SELECT * FROM `tbl_prj_analysis_stock`  where  as_no_root=1  and prj_no='".$GR_Vals['prj_no']."'  ";  # limit 0,30
$arr_stock_history['keys'] ='stock_code';
$arr_stock_history['multi_keys'] =1;

$get_stock_history=php_mysql_Query($arr_stock_history,$connect);


if($test_on) { 
	print_r($GR_Vals);
	print_r($get_stock_history['multi_keys']); 
	
	exit;
	}


$max_width=$tbl_width['i3t']*0.97;

					
	 $cts_tags="<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px; font-size:15px;'  width=$max_width  align=center>";


						 $cts_tags.="<tr><td colspan=10>변동률이 |5%| 이상이고 거래대금이 300억 이상인 종목,    기본 전략 : 대량거래(#4) </td></tr>
						 <tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:14px;color:blue;' align=center  height='49px;' ><td style='font-weight:bold;color:black;font-size:20px;' width=170px;>종목명</td><td>일자</td><td >고가대비<br>(당일/기준일고가)</td><td >거래량증감<br>(당일/기준일)</td></tr>";







                                                                            #  시작 : 종목의 당일 상승률을 기준으로 다중 정렬을 해줌

																					foreach($get_stock_history['multi_keys'] as $multi_key => $multi_value) {   # start of foreach

																																								    	  rsort($multi_value);

																																		                            	$get_stock_multi_info=get_stock_info($multi_key,$connect);

																																										# 패스 조건
																																										# 1) 당일 등락률이  +5% 이상 or -5% 이하
																																										# 2) 거래대금이 200억 미만인경우 
																																										
																																										if($get_stock_multi_info['stock_rate']<5 and  $get_stock_multi_info['stock_rate'] >-5) continue;
																																										if($get_stock_multi_info['stock_vol_cap']<300) continue;

																																										$stock_data[$multi_key]=$multi_value;
																																										$stock_rate_sort[$multi_key]=$get_stock_multi_info['stock_rate'];

																																										$stock_info[$multi_key]=$get_stock_multi_info;
																															}
                                                                            #  끝 : 종목의 당일 상승률을 기준으로 다중 정렬을 해줌

																			                                               arsort($stock_rate_sort);  # 상승률 순으로 정렬함.
																														   
                                                                                                                            
																											foreach($stock_rate_sort as $stock_code => $gsh_stock_rate) {   # start of foreach

																												                          $multi_stock_value=$stock_data[$stock_code];																											                          
  																																		  $get_stock_info=$stock_info[$stock_code];

																												                          $get_tot_num=count($multi_stock_value);																																	  

																																																																					
																																		foreach($stock_data[$stock_code] as $gsh_key => $gsh_value) {   # start of multi_foreach

																																															  $up_day=calender_str(3,13,$gsh_value['as_uDate']);		

																																																																								if(!empty($gsh_value['stock_memo'])) {

																																																																								
																																																																									$memo_tr="<tr style='font-size:13px; text-align:left;height:40px;'><td colspan=6><img src='../img/micon2.gif'> ".$gsh_value['stock_memo']."</td></tr>";

																																																																								}  else																												
																																																																									{ 
																																																																								
																																																																									$memo_tr="<tr><td></td></tr>"; 
																																																																								}

																																																																							#	$memo_tr="<tr style='font-size:13px; text-align:left;height:40px;'><td colspan=6><img src='../img/micon2.gif'> ".$gsh_value['stock_memo']."</td></tr>";



																																																$cts_tags.="<tr style='font-size:13px; text-align:center;height:37px;'>";

																																																if($gsh_key==0)  $cts_tags.="<td rowspan=".$get_tot_num." style='font-size:15px;'><font style='font-size:19px;font-weight:bold;'><a onclick=\"go_to_url('".$get_stock_info['stock_name']."','".$get_stock_info['stock_code']."')\" style='cursor:hand;'> $kk".$get_stock_info['stock_name']."</a></font><br>
																																																                                     ".deco_txt($get_stock_info['stock_rate'],131,0)." <br>
																																																									 ".deco_txt($get_stock_info['stock_vol_cap'],133,500)."  억 <br> 
																																																									 ".deco_txt($get_stock_info['stock_vol']/10000,133,300)." 만주<br>
																																																									 (고가)".deco_txt($get_stock_info['stock_high_price'],3,0)."
																																																									 </td>

																																																									 ";
																																															
																																															
																																															$cts_tags.="<td >".$up_day['unix_str']."</td>";


																																															$cts_tags.="<td>".deco_txt(($get_stock_info['stock_high_price']/$gsh_value['stock_high_price']-1)*100,132,0)." (".deco_txt($gsh_value['stock_high_price'],3,0).")</td>";
																																															
																																															 $cts_tags.="

																																															<td> ".deco_txt(($get_stock_info['stock_vol']/$gsh_value['stock_vol']-1)*100,132,50)."  (".deco_txt($gsh_value['stock_vol']/10000,133,300)." 만주) </td>
																																															
																																															</tr>";

																																														#	$cts_tags.= $memo_tr;
																																															#

																																		} # end of multi_foreach		


																																		$cts_tags.= "<tr>$dot_line</tr>";
																																		


																											 } # end of foreach

																													

						

                      

						$cts_tags.="</table>";

  
  # 번호가 있다면.. 게시물 내용을 불러올 것	 





 
 echo"<meta charset='utf-8'>";

  echo "<html>


        <head>
             <title>report</title>    
			 $style_css		
			 

	<script language=\"javascript\">
     
																	function      go_to_url(go_keys,no) {  // 

																                                                                               go_to_url_tags  ='daily_news.php?mode=search&key_word=\''+go_keys+'\'';																								
																																			  window.open(go_to_url_tags, 'news_d1');


																																			  go_to_url_tags  ='daily_news.php?mode=gt&type=d4&key_word=\''+go_keys+'\'';																								
																																			  window.open(go_to_url_tags, 'news_d4');


																																			 // go_to_url_tags  ='prj_yehior.php?mode=ashv&stock_code='+no;																								
																																			 // window.open(go_to_url_tags, 'news_t4');


																																			  	go_to_urls_t3  ='daily_news.php?mode=top_pi_history&stock_code='+no;																								
																																	                		  window.open(go_to_urls_t3, 'news_t3');




																					}

		</script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\"   >";

    

echo $cts_tags;






echo "</body></html>";


exit;


 ################### end of prj_vieW #######################
}
################### end of prj_vieW #######################







################### start of analysis_update #######################
 function trade_today_history_all_insert($connect) {  ## 당일 매매종목 결산
################### start of analysis_update #######################
$cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');
$prj_no=$GR_Vals['prj_no'];
$as_no=$GR_Vals['as_no'];



	
	# 변수 할당

$tbl_tags=("
	<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #cccccc; border-left:0; border-bottom:0;\" class=\"__se_tbl\" width=100%><tbody>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" width=\"577\">1) 지수동향<br><br></td>
<td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" width=\"577\" rowspan=2>3) 거래대금 상위 30종목<br><br></td>
</tr>

<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" width=\"577\">2)투자자별 매매동향<br><br></td>
</tr>
<tr><td colspan=2 style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" >1)<br> 2)<Br>  3)<br></td></td>

</tr>
</tbody>
</table>
	");


$tbl_tags_thema=("
	<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #cccccc; border-left:0; border-bottom:0;\" class=\"__se_tbl\" width=100%><tbody>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" >1) <br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" ><br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" >2) <br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" ><br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" >3) <br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" ><br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" >4) <br></td></tr>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" ><Br></td></tr>
</tbody>
</table>
	");

$tbl_tags_trade=("
	<table border=\"0\" cellpadding=\"0\" cellspacing=\"0\" style=\"border:1px solid #cccccc; border-left:0; border-bottom:0;\" class=\"__se_tbl\" width=100%><tbody>
<tr><td style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" ><br></td></tr>

</tbody>
</table>
	");






//<tr><td colspan=2 style=\"border:1px solid #cccccc; border-top:0; border-right:0; background-color:#ffffff\" ><p>&nbsp;</p></td>
$default_cts="
1) 당일 시장 마감 ( 지수, 자금동향, 투자자별 매매현황, 아시아시장 )<br>
$tbl_tags
<br><br><br>
2) 테마 및 급등 종목<br>
$tbl_tags_thema
<Br>
3) 당일 매매 마감<br>
$tbl_tags_trade
";


$test_on=0;

 if($test_on==2) 		{ print_r($GR_Vals);  exit; }

if($GR_Vals['mode_two']!='trade_analysis' ) {
						
						$today_ptime=calender_str(1,0,time());
									$rtime=$today_ptime['unix_str'];

										$tr_text_input= "

																  <script type=\"text/javascript\">
																													
																													function     submit_Confirm(v) {		
																																																  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
																																																  contents= document.getElementById('ir1').value;

																																															if(contents=='<p>&nbsp;</p>') { 
																																																				 alert('내용없음'); 

																																																				  if (confirm(\"등록하시겠습니까?\")) { 
																																																				 v.contents.value='';
																																																				  }
																																																				  else {																																															
																																																				   return;
																																																				}
	
	
																																																	}  
																																																																																														
																																											  v.submit();

																																							} // end of submit_Confirm
																			</script>";






			$tr_text_input.= "<table width=100%>
			                               <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
										   <input type=\"hidden\" name=\"mode\" value=\"tthai\">
										   <input type=\"hidden\" name=\"go\" value=\"".$GR_Vals['go']."\">
										  <input type=\"hidden\" name=\"mode_two\" value=\"trade_analysis\">
										  <input type=\"hidden\" name=\"prj_no\" value=\"".$prj_no."\">

										  <Tr><Td colspan=4> <font style='color:gray;font-size:14px;'> # trade_today_history_all_insert  >> tthai </font></td></tr>

												 <tr align=\"left\">
													 <td align='left' style='padding-top:15px;' colspan=4>     
													 키움 당일매매(매도금액 순 정렬후 복사)
												<input type='text' name='uDate'  id='rtime_1' value='$rtime' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_1','','0')\" style='cursor:hand'>

												<input type=checkbox name='final_up' value='1'>마감

												<input type=button value='등 록' onclick=\"javascript:submit_Confirm(document.myform);\" style='width:100px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'>
												</tD></tr>

												<tr><Td>

												*  매도금액 : <input type=\"text\" name=\"sell_cap\" value=\"\" size=7 class=form_nc $auto_clear_tag >
												*  손익 <input type=\"text\" name=\"profit\" value=\"\" size=7 class=form_nc $auto_clear_tag >
												*  손익률 : <input type=\"text\" name=\"profit_rate\" value=\"\" size=7 class=form_nc $auto_clear_tag >


												*  투자자산(시초) : <input type=\"text\" name=\"asset_cap\" size=7 class=form_nc $auto_clear_tag >



												$calender_js
																
												</td></tr>";




										
          
	# 시작 :스마트 에디터 불러오기
                                           	$tr_text_input.= "<tr><td colspan=4>";		
															$tr_text_input.="<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
															$tr_text_input.="<textarea name=contents id=\"ir1\" style=\"width:100%; height:500px;display:none;\">$default_cts</textarea>";

															$tr_text_input.= ("
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


                                  $tr_text_input.= "<tr><td colspan=4>

								  										  KOSPI <input type=\"text\" name=\"kospi\" size=4> pt <input type=\"text\" name=\"kospi_yrate\" size=3>																		  
							  										      KODAQ <input type=\"text\" name=\"kosdaq\" size=4>pt <input type=\"text\" name=\"kosdaq_yrate\" size=3>

																		  <input type=checkbox name='add_up' value='1'>*추가

								  </td></tr>";



								$tr_text_input.= "<tr><td colspan=4>첫번째 계좌(오전매매)<br><textarea name=tr_one style=\"width:755px; height:212px;\"></textarea></td></tr>";
								$tr_text_input.= "<tr><td colspan=4>두번째 계좌(오후매매)<br><textarea name=tr_two style=\"width:755px; height:212px;\"></textarea></form></td></tr></table>";

					echo "$style_css";

					echo $tr_text_input;

		  exit;

}

else  {

             $today= explode(' ',$GR_Vals['uDate'])[0];    



     # 계좌정보가 2개인 경우


	  for($ti=0;$ti<2;$ti++) {

		                         if($ti==0) $tr_info=$GR_Vals['tr_one'];
								 else if($ti==1) $tr_info=$GR_Vals['tr_two'];

								 $acc_no=$ti+1;

								 if(empty($tr_info)) continue;

								 
		       
							 $qry_delete="delete from tbl_trade_review where sell_Date='".$today."' and acc_no='".$acc_no."'";
							   if(!$test_on) mysqli_query($connect,$qry_delete); 
							   table_auto_increment("tbl_trade_review",$connect);

							   echo "<font color=red>$acc_no".$qry_delete."</font><br>";


                                          $as_vals=explode("\n",preg_replace("/[#\&\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $tr_info)); # 불필요한 특수문자들 제거후
		
			   							   foreach($as_vals as $si_key=>$si_array)   { 

																														  $stock_trade_array=explode("\t",$si_array);   # 당일 매매 내역을 종목별로 배열에 할당

																														   if($si_key<2) continue;

																														   ## 당일 매매 종목을 top 종목순위 테이블에 업데이트 시킬것
																																	   $qry_stock_up="update  `tbl_daily_stock_vol`   set  today_tr_cap='".$stock_trade_array[7]."'  where stock_code='".$stock_trade_array[0]."' and uDate='".$today."'  ";
																								  
																															 if($test_on) 		 print_r($qry_stock_up);
																																		  else       mysqli_query($connect,$qry_stock_up); 
																														 

																					                                        if(empty($stock_trade_array[5]) or  $stock_trade_array[7]< 10000 ) continue;  # 매도가격이 없거나 매도금액이 1만원 미만이면.. 건너뜀

																														  $buy_Date="";
																														  $sell_Date="";

																														  $tr_chk_plus=0;
																														  $tr_chk_minus=0;

																														   if($stock_trade_array[2]) $buy_Date=$today; #매수가격이 있으면 매수날짜
																														   if($stock_trade_array[5]) $sell_Date=$today; #매도 가격이 있으면 매도날짜

																														   if($stock_trade_array[9]>0)  $tr_chk_plus=1;
																														   else  $tr_chk_minus=1; 


																														   # all_info_stock에서 정보가져오기

																														   $qry_stock['qry']="select * from all_stock_info where stock_code='".$stock_trade_array[0]."'";
																															$stock_info_array=php_mysql_Query($qry_stock,$connect);     
																															$stock_info=$stock_info_array['value'][0];

																															#print_r($stock_info_array);
																															#print_r($qry_stock);

																														   $qry_ins['qry']="insert into tbl_trade_review set  stock_code='".$stock_trade_array[0]."' ,buy_price='".$stock_trade_array[2]."' ,buy_qty='".$stock_trade_array[3]."' ,buy_cap='".$stock_trade_array[4]."' ,sell_price='".$stock_trade_array[5]."' ,sell_qty='".$stock_trade_array[6]."' ,sell_cap='".$stock_trade_array[7]."' ,profit='".$stock_trade_array[9]."' , tr_chk_plus='".$tr_chk_plus."'  , tr_chk_minus='".$tr_chk_minus."' , profit_rate='".$stock_trade_array[10]."' , stock_cap='".$stock_info['stock_cap']."', stock_vol='".$stock_info['stock_vol']."'  , stock_vol_cap='".$stock_info['stock_vol_cap']."' , stock_high_price='".$stock_info['stock_high_price']."' , stock_rate='".$stock_info['stock_rate']."',  buy_Date='".$buy_Date."' , sell_Date='".$sell_Date."' , acc_no='".$acc_no."'   ";

																														   $qry_ins['result']=1;


																															  if($test_on) {
																																		   print_r($stock_trade_array);
																																		   print_r($qry_ins);

																																		 #  $test_on=1;

																																		   echo"<tr><td>".$stock_trade_array[1]."</td><td>".$stock_trade_array[2]."</td><td>".$stock_trade_array[3]."</td><td>".$stock_trade_array[4]."</td><td>".$stock_trade_array[5]."</td><td>".$stock_trade_array[6]."</td><td>".$stock_trade_array[7]."</td><td>".$stock_trade_array[9]."</td><td>".$stock_trade_array[10]."</td><td>".$stock_trade_array[13]."</td></tr>";

																															  }    else    php_mysql_Query($qry_ins,$connect);     

																													   }

	  } #end of 계좌정보가 2개인 경우






if($GR_Vals['final_up']) {

 $qry_cmt_ins['qry']="insert into tbl_trade_review_comment set  asset_cap='".$GR_Vals['asset_cap']."', sell_cap='".$GR_Vals['sell_cap']."',profit='".$GR_Vals['profit']."',profit_rate='".$GR_Vals['profit_rate']."',contents='".$GR_Vals['contents']."',  kospi='".$GR_Vals['kospi']."',kospi_yrate='".$GR_Vals['kospi_yrate']."',kosdaq='".$GR_Vals['kosdaq']."',kosdaq_yrate='".$GR_Vals['kosdaq_yrate']."', uDate='".$GR_Vals['uDate']."' ";
}

  if(!$test_on)  php_mysql_Query($qry_cmt_ins,$connect);     

## 완료된후에 오늘 매매 종목 번호를 tbl_daily_stock_vol에 업데이트할것

$qry_stock_tr['qry']="SELECT  no,stock_code,sum(sell_cap) as tot_sell_cap  from `tbl_trade_review`  where  sell_Date='".$sell_Date."' group by stock_code";
$stock_tr_array=php_mysql_Query($qry_stock_tr,$connect);     


foreach($stock_tr_array['value'] as $tr_no => $tr_value){

		   $qry_today_tr_up="update  `tbl_daily_stock_vol`   set  today_tr_no='".$tr_value['no']."',today_tr_cap='".$tr_value['tot_sell_cap']."' where stock_code='".$tr_value['stock_code']."' and  uDate='".$sell_Date."' ";
           
	 if($test_on)echo "$qry_today_tr_up<br><br>";
	 else mysqli_query($connect,$qry_today_tr_up); 

}

#exit;

if(empty($test_on)) 	 { 	
	if($GR_Vals['go']==1) Header("Location:daily_news.php?mode=stock_std_list");
    else if($GR_Vals['go']==2) Header("Location:daily_news.php?mode=top_pi_list");
    else  Header("Location:$cur_php?mode=trl");
}
	

				
}


		
																																						
																																		








exit;



 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################





################### start of trade_review_lisT #######################
 function trade_review_lisT($connect) {   ### trl :: 매매복기
################### start of trade_review_lisT #######################
global $cur_php;
global $admin_info;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";


get_Permit($admin_info,"$cur_php?mode=trl");


$GR_Vals=Get_Vals('mode');



if($mobile==1)  $target['tdl']='';
	else  $target['tdl']="target='prj_u2'";

if($GR_Vals['pop']=="si")  { 

	$target['tdl']="target='s2'";

}





if($GR_Vals['limit_tr_cap']) { 
	$limit_cap= $GR_Vals['limit_tr_cap']; 


}
else { 
$limit_cap="금액"; 
}

    $arr_srch['qry']="SELECT sum(buy_cap) as buy_sum,sum(sell_cap) as sell_sum ,sum(profit)as tr_profit, count(*) as tr_cnt ,sum(tr_chk_plus) as tr_chk_plus ,sum(tr_chk_minus) as tr_chk_minus ,sell_Date,buy_Date FROM `tbl_trade_review` GROUP BY sell_Date desc";		



  
 	$trade_review_array=php_mysql_Query($arr_srch,$connect);

	

	$tr_text_input= "<table border=0>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
														   <input type=\"hidden\" name=\"mode\" value=\"trl\">
														<tr height='30px;' style='vertical-align:top;'>
																					
														<td>													
																<img src='../img/smile.gif'> <input type='text'  size='10'  name='limit_tr_cap' id='srch_jongmok' value='".$limit_cap."' class=form_nc style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:30px;font-size:17px;'  onfocus=this.value=''; onBlur=\"disp_hidden_key();\"; onkeyup=\"if(window.event.keyCode==13){submit_srch_Confirm(document.myform);}\"><input type=hidden id='hidden_key'>
											</td>
											</tr>
									</table>";



  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css

        </head>

			  <script type=\"text/javascript\">

						 function      submit_srch_Confirm(v) {					
																							v.submit();
																																																																									
																						}

			</script>



        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['i3t'].">

        <table  align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> 		";   # start of tbl 000

        echo "<tr><td>";




$tra_tags= "<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:15px;color:blue;' align=center  height='39px;'><td>n</td><td width=100>매매일</td><td width=120px;>자산</td><td width=50px;>회전율</td><td width=80px;>원금대비</td><td width=120>매수금액</td><td width=120>매도금액</td><td width=100>손익</td><td width=100>수익률</td><td width=50>매매횟수</td><td td width=50>수익종목</td><td width=100>손실종목</td><td width=100 >승률</td></tr>";


if($GR_Vals['limit_tr_cap']) { 

foreach($trade_review_array['value'] as $tra_key => $tra_value) {

	    if($GR_Vals['limit_tr_cap'] and $GR_Vals['limit_tr_cap']> $tra_value['sell_sum']) continue;

     $tr_rate=$tra_value['tr_profit']/$tra_value['sell_sum']*100;
	 $trl_value[]=$tra_value;
	 $trl_key[]=$tr_rate;
}
  array_multisort($trl_key,SORT_DESC,$trl_value,);

}

else $trl_value=$trade_review_array['value'];

$tot= count($trl_value);

foreach($trl_value as $tra_key => $tra_value) {

	  $tr_day=calender_str(3,1,$tra_value['sell_Date']); 

	 #   if($GR_Vals['limit_tr_cap'] and $GR_Vals['limit_tr_cap']> $tra_value['sell_sum']) continue;


    $arr_cmt['qry']="SELECT * FROM `tbl_trade_review_comment` where uDate='".$tra_value['sell_Date']."'";																																												
   	$trade_cmt_array=php_mysql_Query($arr_cmt,$connect);

    $trade_cmt=$trade_cmt_array['value'][0];

  if($trade_cmt['kospi']>0) { 
	  
						  $kospi_rate=deco_txt($trade_cmt['kospi_yrate']/($trade_cmt['kospi']-$trade_cmt['kospi_yrate'])*100,131,0);
						  if($trade_cmt['kosdaq']!=0) $kosdaq_rate=deco_txt($trade_cmt['kosdaq_yrate']/($trade_cmt['kosdaq']-$trade_cmt['kosdaq_yrate'])*100,131,0);
						  else $kosdaq_rate="";

						  $index_tags= "<br><Br><font style='font-size:11px;'>K:".$kospi_rate."<Br>Q:".$kosdaq_rate;

  }  else $index_tags="";

 $nn++;


if($GR_Vals['limit_tr_cap']==$tra_value['sell_sum']) { $bg_color="style='background-color:yellow;'"; 

                            $cur_rank_tag=$nn."/".$tot;                        

																							}
else $bg_color="";



$tra_tags.= "<tr align=center height='40px;' $bg_color><td>$nn</td><td nowrap><a href='$cur_php?mode=tdl&pop=".$GR_Vals['pop']."&sell_Date=".$tra_value['sell_Date']."' ".$target['tdl'].">".$tr_day['unix_str']."</a>".$index_tags."</td>";
	  
if($trade_cmt['asset_cap'])  $tra_tags.= "<td>".deco_txt($trade_cmt['asset_cap'],3,0)."</td><td>".deco_txt($trade_cmt['sell_cap']/$trade_cmt['asset_cap'],31,0)."</td><td>".cur_deco_txt($opt_deco,$trade_cmt['profit']/$trade_cmt['asset_cap']*100,20,5,-5)."</td>";
else $tra_tags.="<Td colspan=3></td>";


  $tra_tags.= "	  
  <td>".deco_txt($tra_value['buy_sum'],3,0)."</td><td><a href='$cur_php?mode=trl&pop=".$GR_Vals['pop']."&limit_tr_cap=".$tra_value['sell_sum']."'>".deco_txt($tra_value['sell_sum'],3,0)."</td><td>".deco_txt($tra_value['tr_profit'],1,0)."</td><td>".deco_txt($tra_value['tr_profit']/$tra_value['sell_sum']*100,131,0)."</td><td>".$tra_value['tr_cnt']."</td><td>".$tra_value['tr_chk_plus']."</td><td>".$tra_value['tr_chk_minus']."</td><td>".deco_txt(($tra_value['tr_chk_plus']/$tra_value['tr_cnt']*100),132,50)."</td></tr>";

 if($trade_cmt_array['value']) $tra_tags.= "<tr><td></td><td colspan=8 style='font-size:12px;color:#610B0B;' width=100%><img src='../img/dot_b.gif'>".$trade_cmt_array['value'][0]['cmt']."</td></tr>";

      $tot_chk_minus[]=$tra_value['tr_chk_minus'];
	  $tot_chk_plus[]=$tra_value['tr_chk_plus'];
  	  $tot_cnt[]=$tra_value['tr_cnt'];
	  $tot_profit[]=$tra_value['tr_profit'];

     $tot_buy_sum[]=$tra_value['buy_sum'];
 	  $tot_sell_sum[]=$tra_value['sell_sum'];

	     $tra_tags.= "<tr>$dot_line</tr>";
}



   $tra_tot_tags="<tr  style='border: 1px dashed blue; border-radius: 5px; background-color:white; border-spacing:7px;font-size:15px;color:blue;' align=center  height='39px;'><td colspan=3>".$tr_text_input."</td><td colspan=2><합계></td><td>".deco_txt(array_sum($tot_buy_sum),3,0)."</td><td>".deco_txt(array_sum($tot_sell_sum),3,0)."</td><td>".deco_txt(array_sum($tot_profit),1,0)."</td><td>".deco_txt((array_sum($tot_profit)/array_sum($tot_sell_sum)),2,0)."</td><td>".array_sum($tot_cnt)."</td><td>".array_sum($tot_chk_plus)."</td><td>".array_sum($tot_chk_minus)."</td><td>".deco_txt((array_sum($tot_chk_plus)/array_sum($tot_cnt)*100),132,50)."</td></tr>
   
   ";
         
   echo "<Table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:14px;' width=95%>";   # start of tbl 000-001

echo "<tr><td colspan=4><a href='$cur_php?mode=tthai&pop=".$GR_Vals['pop']."'>W</a>  :::  <a href='$cur_php?mode=trl&pop=".$GR_Vals['pop']."'>trade_review_list</a>  :::  <a href='daily_news.php?mode=sal'>stock analysis list</a></td><td colspan=2>".$cur_rank_tag."</td></tr>";

   echo    $tra_tot_tags;
   echo $tra_tags;
  

echo "</table>";   # end of tbl 000-001
		echo "</td></td>";




echo "</table>"; # end of tbl 000

echo "</body></html>";



 ################### end of trade_review_lisT #######################
}
################### end of trade_review_lisT #######################



################### start of t.rade_review_daily_lisT #######################
 function trade_daily_lisT($connect) { 
################### start of t.rade_review_daily_lisT #######################
global $cur_php;
global $admin_info;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";

$mobile=0;
$test_on=0;

$GR_Vals=Get_Vals('mode');			 


$today = date("Y-m-d");



$target['tdw']="target='prj_u3'";

if($mobile==1)  $target['tdw']='';

if($GR_Vals['pop']=="si")  { 

	$target['tdw']="target='s3'";

}










	  if($GR_Vals['opt']=='del' ) {

		         		   $query_del="delete from tbl_trade_review  where no='".$GR_Vals['no']."'  ";
					  
									 if($test_on) 		 print_r($query_del);
										 else   $result_del=mysqli_query($connect,$query_del); 

					  }




                     # 시작 :전체 종목 명 가져오기

																										   $arr_stock_srch['qry']="select stock_code,stock_name,stock_cap,stock_vol,stock_vol_cap,stock_high_price,stock_rate from all_stock_info";																											
																										   $arr_stock_srch['keys'] ='stock_code';
																										  # $arr_stock_srch['multi_keys'] =0;
 
																											$stock_srch_array=php_mysql_Query($arr_stock_srch,$connect);

                                                                                                          # 종목 네임 배열
																											$all_stock_name=$stock_srch_array['multi_keys'];

 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css

	       <script language=\"javascript\">
     
	 				function     go_to_url() {  // 															
																																			 go_to_url_tags  ='$cur_php?mode=trcl';																								
																																			  window.open(go_to_url_tags, 'prj_u3');
																					}
			

	 </script>
 

        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['i3t'].">

        <table width=".$tbl_width['i3t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


		";





## 시작::코멘트 리스트

echo "<tr><td colspan=10><table>";


  				$query_cmt=" SELECT * FROM `tbl_trade_review_comment` where uDate= '".$GR_Vals['sell_Date']."' ";
											$result_query_cmt=mysqli_query($connect,$query_cmt); 

									$cmt_value = mysqli_fetch_array($result_query_cmt);


 echo "<tr><td bgcolor=black style='color:white;text-align:center;font-size:30px;font-weight:bold;'>오늘의 시장 및 매매 정리 </td></tr>";
 echo "<tr><td>".$cmt_value['contents']."</td></tr>";


echo "</table></td></tr>";
## 끝::코멘트 리스트





# 새로 고치면서, 시세 업데이트함
$update_tags="<a href=".$cur_php."?mode=tdl&sell_Date=".$GR_Vals['sell_Date']."&opt=update>";


echo "<tr><td>";

   echo "<Table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:14px;' width=95%>";
			   echo"<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:15px;color:blue;' align=center  height='39px;'><td colspan=2>종목명<br>($update_tags".$GR_Vals['sell_Date'].")</td><td width=30>구분</td><td>매수가격</td><td>매수금액</td><td>매도가격</td><td>매도금액</td><td>손익금액</td><td>수익률</td></tr>";


$qry['qry']= "select * from tbl_trade_review where sell_Date='".$GR_Vals['sell_Date']."' order by vip desc";

$result_view=php_mysql_Query($qry,$connect);     

if($test_on) {

	print_r($qry);
	#print_r($result_view['value']);
	
}



    $vip_img[2]="<img src='../img/one_icon5.gif'>";
    $vip_style[2]=" style='font-size:17px;font-weight:bold;color:red;background:yellow;' ";


	$vip_img[1]="<img src='../img/one_icon3.gif'>";
    $vip_style[1]=" style='font-size:17px;font-weight:bold;color:white;background:blue;' ";




 foreach($result_view['value'] as $tr_key => $tr_value) {

     
  	$tr_Date=explode(" ",$tr_value['uDate'])[0];

	 $stock_vol_tags=get_stock_vol_info($tr_value['stock_code'],$tr_Date,1,$connect);






    # 시세 업데이트

			  if($GR_Vals['opt']=='update' and $GR_Vals['sell_Date']==$today)  {

							 $arr_stock_update['qry']="update tbl_trade_review set  stock_cap='".$all_stock_name[$tr_value['stock_code']]['stock_cap']."'  , stock_vol_cap='".$all_stock_name[$tr_value['stock_code']]['stock_vol_cap']."', stock_vol='".$all_stock_name[$tr_value['stock_code']]['stock_vol']."' ,  stock_high_price='".$all_stock_name[$tr_value['stock_code']]['stock_high_price']."', stock_rate='".$all_stock_name[$tr_value['stock_code']]['stock_rate']."'   where no='".$tr_value['no']."' ";																											
							 $arr_stock_update['result']=1;

						 if($test_on) 		 print_r($arr_stock_update);
							 else $result_update=php_mysql_Query( $arr_stock_update,$connect);     

					# 
					  } # update
    # 시세 업데이트



	   if($tr_value['buy_Date']<>$tr_value['sell_Date']) $tr_type="스윙"; else $tr_type="단기"; 

      $modify_tags="";
	  $view_tags="";

     if(strtotime($tr_value['uDate'])<0) 	 $modify_tags="<a href='$cur_php?mode=tdw&pop=".$GR_Vals['pop']."&no=".$tr_value['no']." '   ".$target['tdw']." ".$vip_style[$tr_value['vip']]."><img src='../img/rep.gif'></a>";        
	else	  $view_tags="<a href='$cur_php?mode=tdv&pop=".$GR_Vals['pop']."&no=".$tr_value['no']."' ".$target['tdw']." ".$vip_style[$tr_value['vip']].">";


	# 새로 고치면서, 시세 업데이트함
    if($admin_info['acc_permit'] and  $GR_Vals['sell_Date']==$today) $del_tags="<a href=".$cur_php."?mode=tdl&no=".$tr_value['no']."&sell_Date=".$GR_Vals['sell_Date']."&opt=del><img src='../img/ic/12-em-cross.png'></a>";




	 echo "<tr height='40px;' align=right>

				<td rowspan=3> ".$view_tags.$all_stock_name[$tr_value['stock_code']]['stock_name']."</a> <br><br>".cur_deco_txt($opt,$tr_value['stock_rate'],10,0,0)."</td>

	 				<td  rowspan=3 width=14px; valign=top><br>".$vip_img[$tr_value['vip']]."</td>
				<td align=center>$tr_type</td> <td>".deco_txt($tr_value['buy_price'],3,0)."</td>
				<td align=right>".deco_txt($tr_value['buy_cap'],3,0)."</td>";
	 	 echo "                                                                          <td>".deco_txt($tr_value['sell_price'],3,0)."</td><td  align=right>".deco_txt($tr_value['sell_cap'],3,0)."</td>";
 	 	 echo "                                                                          <td  align=right>".deco_txt($tr_value['profit'],1,0)."</td><td>".deco_txt($tr_value['profit_rate'],131,0)."</td>";


		 echo "<td>$modify_tags</td>";


		 echo "</tr>";

   		   echo "<tr><Td colspan=9 style='font-size:15px;'>(거래량) ".deco_txt($tr_value['stock_vol']/10000,133,300)."  만주  (대금) ".deco_txt($tr_value['stock_vol_cap'],133,500)."  억   (시총)".deco_txt($tr_value['stock_cap'],3,0)."  억   $del_tags</td></tr>";
		   echo "<tr><Td colspan=9 style='font-size:15px;'>".$stock_vol_tags."</td></tr>";
		   echo "<tr>$dot_line</tr>";


		 $tot_buy[]=$tr_value['buy_cap'];
 		 $tot_sell[]=$tr_value['sell_cap'];

  		 $tot_profit[]=$tr_value['profit'];


 }


	   echo"<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:15px;color:blue;' align=center  height='39px;'><td colspan=2><a href='$cur_php?mode=trl'>".$tr_value['sell_Date']."</a></td><td colspan=2>(매수) ".deco_txt(array_sum($tot_buy),3,0)."</td><td colspan=2>(매도) ".deco_txt(array_sum($tot_sell),3,0)."</td><td>".deco_txt(array_sum($tot_profit),1,0)."</td><td>".deco_txt((array_sum($tot_profit)/array_sum($tot_sell)),2,0)."</td>
	   

	   </tr>";


 echo "</table>";

 echo "</td></tR>";
 echo "</table>";





 ################### end of t.rade_review_daily_lisT #######################
}
################### end of t.rade_review_daily_lisT #######################


################### start of t.rade_review_daily_lisT #######################
 function trade_daily_vieW($connect) { 
################### start of t.rade_review_daily_lisT #######################
global $cur_php;
global $admin_info;

require "./env/e.fnc";
require "./env/inf.fnc";


$GR_Vals=Get_Vals('mode');			 

 $test_on=0;


$qry['qry']= "select * from tbl_trade_review where no='".$GR_Vals['no']."'";
$result=php_mysql_Query($qry,$connect);     

$tra_value=$result['value'][0];
$stock_info=get_stock_info($tra_value['stock_code'],$connect);

if($test_on) {

	print_r($qry);
	print_r($result['value'][0]);

	exit;
}


		    $cat=array('cat_type'=>'tr_stg','disp'=>'view','cat_value'=>$tra_value['chk_list']);

	

		    $get_chkbox_tag = get_chkbox_category($cat,$connect);


if($admin_info['acc_permit'] and $GR_Vals['pop']!="view") $modify_tags="<a href='$cur_php?mode=tdw&pop=".$GR_Vals['pop']."&no=".$tra_value['no']."'>M</a>";




  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css

	       <script language=\"javascript\">
     
			

	 </script>

 

        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['i3t'].">

        <table width=".$tbl_width['i3t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> "; # start of table 000 


echo "<tr><td align=center>";

   echo "<Table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:14px;' width=95%>";

		   echo"<tr style='border: 1px dashed blue; border-radius: 5px; border-spacing:7px;font-size:25px;color:blue;' align=left  height='39px;'><td >".$stock_info['stock_name']."  (".deco_txt($tra_value['stock_rate'],121,"%").") </td><td>$modify_tags</td></tr>";


if($tra_value['stock_vol']>0) {

		   echo "<tr><Td colspan=2 style='font-size:15px;'>(거래량) ".deco_txt($tra_value['stock_vol']/10000,3,0)."  만주  (대금) ".deco_txt($tra_value['stock_vol_cap'],3,0)."  억   (시총)".deco_txt($tra_value['stock_cap'],3,0)."  억   (회전율) ".deco_txt($tra_value['stock_vol_cap']/$tra_value['stock_cap']*100,132,30)."</td></tr>";
}


 echo "<tr>
                   <td colspan=2>
				   <table style='border: 0px dashed orange; border-radius: 15px;  border-spacing:1px;font-size:14px;background-color:#EFF2FB;padding:5px;' width=100% >";
         
     	   echo"<tr style='border: 0px dashed blue; border-radius: 15px; background-color:yellow; border-spacing:7px;font-size:15px;color:blue;' align=center height=50><td width=30>구분</td><td>매수가격</td><td>매수금액</td><td>매도가격</td><td>매도금액</td><td>손익금액</td><td>수익률</td></tr>";


  if($tra_value['buy_Date']<>$tra_value['sell_Date']) $tr_type="스윙"; else $tr_type="단기"; 

       	  echo "<tr align=center style='background-color:white;border-radius: 15px; ' height=50><td>$tr_type</td><td>".deco_txt($tra_value['buy_price'],3,0)."</td><td>".deco_txt($tra_value['buy_cap'],3,0)."</td><td>".deco_txt($tra_value['sell_price'],3,0)."</td><td>".deco_txt($tra_value['sell_cap'],3,0)."</td><td>".deco_txt($tra_value['profit'],1,0)."</td><td>".deco_txt($tra_value['profit_rate'],131,0)."</td></tr>";

echo "</table></td></tR>";


 echo "<tr>
                   <td colspan=2>
				   <table style='border: 0px dashed orange; border-radius: 15px;  border-spacing:1px;font-size:14px;background-color:#EFF2FB;padding:5px;' width=100% >";
         
      	  echo "<tr align=left style='background-color:white;border-radius: 15px; ' height=50><td>$get_chkbox_tag</td></tr>";

echo "</table></td></tR>";

echo "<tr><td  align=center colspan=2> <table style='border: 0px dashed orange; border-radius: 15px; ; border-spacing:5px;font-size:14px;background-color:#EFF2FB;padding:10px;' ><tr><td >";

   
   # 컨텐츠 

 												 $max_width=$tbl_width['i3t']*0.88;
												 $contents=$tra_value['contents'];
  												  $urls="$cur_php?mode=img_pop&type=trade_review&no=".$tra_value['no']."";
												  $contents=base64_Img_decode($urls,$contents,$max_width);

												  echo $contents;


   
   echo "</td></tr></table></td></tr>";



 echo "</table>";

 echo "</td></tR>";
 echo "</table>"; # end of tbl 000





 ################### end of t.rade_review_daily_lisT #######################
}
################### end of t.rade_review_daily_lisT #######################





################### start of analysis_update #######################
 function trade_daily_writE($connect) {  ## 당일 매매 종목을 그래프와 함께 복기함
################### start of analysis_update #######################
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');

$qry['qry']= "select * from tbl_trade_review where no='".$GR_Vals['no']."'";

$result=php_mysql_Query($qry,$connect);     

$tdw_value=$result['value'][0];


  $arr_stock_srch['qry']="select stock_code,stock_name from all_stock_info where stock_code='".$tdw_value['stock_code']."'";																				
  $result_stock=php_mysql_Query($arr_stock_srch,$connect);     
  $stock_name=  $result_stock['value'][0];


if(0) {

	print_r($qry);
	print_r($result['value']);

	print_r( $arr_stock_srch);
	print_r($stock_name);

	exit;

}

# textarea 를 몇개 만들건지?

  $cts_title=array("당일 매매 요약");
  $cts_height=array("1250px");


	  for($i=0;$i<count($cts_height);$i++) {

		  $cts_obj.="oEditors_".$i.".getById['ir_".$i."'].exec('UPDATE_CONTENTS_FIELD',[]);
						      contents= document.getElementById('ir_".$i."').value;							  
							  ";


	  }


  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css
$calender_js
	       <script language=\"javascript\">
     
			 
			 function      chkfrm(f) {	      
				 
																  $cts_obj
		
																 if(0) {	 for(loop = 0; loop < f.length; loop++)  alert(f[loop].name+ '==>' + f[loop].value);
																				return;
																		}

																f.submit();	

												  }


												function      calc_buy_cap() {	      

													alert(1);


												 }




       	 </script>




 

        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" >

        <table  align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\" width='1200px;'> 		";   # start of tbl 000


        echo "<tr><td>			
		

		";

     
             
   echo "<Table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:14px;' width=99%>";   # start of tbl 000-001
echo "<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
				<input type=\"hidden\" name=\"pop\" value=\"".$GR_Vals['pop']."\">
		<input type=\"hidden\" name=\"mode\" value=\"tdu\">
				<input type=\"hidden\" name=\"no\" value=\"".$tdw_value['no']."\">
				<input type=\"hidden\" name=\"sell_Date\" value=\"".$tdw_value['sell_Date']."\">
				<input type=\"hidden\" name=\"buy_Date\" value=\"".$tdw_value['buy_Date']."\">
		";






			 echo "<tr align=center><td colspan=6 style='font-size:30px;'>".$stock_name['stock_name']."</td></tr>"; # 종목명


if($tdw_value['stock_vol']>0) {

		   echo "<tr><Td colspan=6 style='font-size:20px;' align=center>(거래량) ".deco_txt($tdw_value['stock_vol']/10000,3,0)."  만주  (대금) ".deco_txt($tdw_value['stock_vol_cap'],3,0)."  억   (시총)".deco_txt($tdw_value['stock_cap'],3,0)."  억   (회전율) ".deco_txt($tdw_value['stock_vol_cap']/$tdw_value['stock_cap']*100,132,30)."</td></tr>";
}



			   echo"<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:15px;color:blue;' align=center  height='39px;'>
			   <td width=100>매수일</td><td width=100>매수가격</td><td width=120>매수수량</td><td width=120>매수금액</td> <td width=100 rowspan=2>손익</td><td width=100 rowspan=2>수익률</td></tr>";
			   echo "<tr  style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:15px;color:blue;' align=center  height='39px;'><td width=100>매도일</td><td width=120>매도 가격</td><td width=120>매도수량</td><td width=120>매도 금액</td></tr>";
  


if(strtotime($tdw_value['buy_Date'])>0) {
																							$buy_Date_str=$tdw_value['buy_Date'];
																							$buy_price_str=deco_txt($tdw_value['buy_price'],3,0);
																							$buy_qty_str=deco_txt($tdw_value['buy_qty'],3,0);
																							$buy_cap_str=deco_txt($tdw_value['buy_cap'],3,0);
																					}
else{  $buy_Date_str="<input type='text' name='buy_Date'  id='rtime_1' value='".$tdw_value['sell_Date']."' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_1','','0')\" style='cursor:hand'>";

              $buy_price_str="<input type=text size='15' name='buy_price' id='buy_price' class=form_nc value=''>";
              $buy_qty_str="<input type=text size='15' name='buy_qty' id='buy_qty' class=form_nc value=''  onkeyup='calc_buy_cap;'>";
            #  $buy_cap_str="<input type=text size='15' name='buy_cap' id='buy_cap' class=form_nc value=''>";

}
 
 echo " <tr align=center><td>$buy_Date_str</td>";  # 매수일
 echo " <td>$buy_price_str</td>";  # 매수가격
echo " <td>$buy_qty_str</td>";  # 매수수량
echo " <td>$buy_cap_str</td>";  # 매수금액
 echo " <td rowspan=2>".deco_txt($tdw_value['profit'],1,0)."</td>";  # 손익
 echo " <td rowspan=2>".deco_txt($tdw_value['profit_rate'],131,0)."</td>";  # 수익률

echo "</tr><tr align=center>";

 echo " <td>".$tdw_value['sell_Date']."</td>";  # 매도일
 echo " <td>".deco_txt($tdw_value['sell_price'],3,0)."</td>";  # 매도가격
echo " <td>".deco_txt($tdw_value['sell_qty'],3,0)."</td>";  # 매도수량
echo " <td>".deco_txt($tdw_value['sell_cap'],3,0)."</td>";  # 매도금액


echo "</tr>";



		   $cat=array('cat_type'=>'tr_stg','disp'=>'select','cat_name'=>'chk_list','cat_value'=>$tdw_value['chk_list']);
		    $get_chkbox_tag = get_chkbox_category($cat,$connect);


  echo"<tr><td colspan=6>$get_chkbox_tag</td></tr>";


$checked[$tdw_value['vip']]="checked";


  echo"<tr><td colspan=6><input type=\"radio\" name=\"vip\" value=2 ".$checked[2].">잘한매매 <input type=\"radio\" name=\"vip\" value=1 ".$checked[1].">못한매매</td></tr>";




	echo ("

   <tr >
		<td colspan=10 width=98%>								 
  ");

				# 시작 :스마트 에디터 불러오기
				echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>
					     
			";


echo "<table border=0 width=100%>";


if(!$tdw_value['contents']) $default_cts="<font style='font-size:20px;'>일봉<br><br>5분봉<br><br>1분봉<br><br>60틱<br><br>";


for($i=0;$i<count($cts_height);$i++) {


						 echo "<Tr><td><br><img src='../img/micon1.gif'> $cts_title[$i]</td></tr>";

						 if($i==0) $contents=$default_cts.$tdw_value['contents']; 
						 else $contents=$default_cts;

                
				echo"<tr><td><textarea name=contents[] id=\"ir_$i\" style=\"width:1150px; height:".$cts_height[$i]."; display:none;\">".$contents."</textarea></td></tr>";

				echo "	<script type=\"text/javascript\">
								  var  oEditors_$i = [];
								  nhn.husky.EZCreator.createInIFrame({
									oAppRef: oEditors_$i,
									elPlaceHolder: \"ir_$i\",
									sSkinURI: \"../smart_editor/SmartEditor2Skin.html\",
									fCreator: \"createSEditor2\"
								});						 
			</script>";

}


echo"		<tr><Td>		<input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;cursor:hand;'></td></tR>        ";


echo "</table>";

echo "</td></tr>";



echo "</table>";   # end of tbl 000-001
		echo "</td></tr>";


echo "</form></table>"; # end of tbl 000

echo "</body></html>";


exit;


 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################




################### start of analysis_update #######################
 function trade_daily_updatE($connect)  { 
################### start of analysis_update #######################

require "./env/e.fnc";

# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');

$buy_Date=explode(' ',$GR_Vals['buy_Date'])[0];


$test_on=0;

if($test_on) print_r($GR_Vals);

//$GR_Vals['contents']=addslashes($GR_Vals['contents']);



 foreach($GR_Vals['chk_list'] as $cl_key => $cl_value){

	     $cl_tags.=$cl_value."-";

 }

				  $cl_tags=substr($cl_tags,0,-1);


				  				



 foreach($GR_Vals['contents'] as $cts_key => $cts_value){

	 $cts_tags.=addslashes($cts_value)."<Br>";
 }

 $buy_cap=$GR_Vals['buy_qty']*$GR_Vals['buy_price']*0.999851;



if($buy_Date) $up_str=", vip='".$GR_Vals['vip']."', buy_price='".$GR_Vals['buy_price']."', buy_qty='".$GR_Vals['buy_qty']."',buy_cap='".$buy_cap."',buy_Date='".$buy_Date."' ,uDate='".date("Y-m-d H:i:s")."' ";
else $up_str=",uDate='".date("Y-m-d H:i:s")."'  ";


              	$arr_qry['qry']="update tbl_trade_review set  contents='".$cts_tags."' , chk_list='".$cl_tags."'  $up_str where no= '".$GR_Vals['no']."'";

				$arr_qry['result']=1;

				
				if($test_on) 	print_r($arr_qry);
				 else  php_mysql_Query($arr_qry,$connect);


  mysqli_close($connect);


if($test_on) {


  echo "test 중입니다.";

}

else {

				 if($GR_Vals['pop']=="si")      echo "<body  onload=\"javascript:window.open('daily_news.php?mode=pop_url&pop_type=4&stock_no=".$GR_Vals['no']."','pop_hidden','width=10, height=10')\">"; 

							else  {
										echo "
																<html>


																				   <script language=\"javascript\">
																			 
																							function     go_to_url() {  // 															

																																																				 go_to_url_tags  ='$cur_php?mode=tdl&sell_Date=".$GR_Vals['sell_Date']."';																								
																																																					  window.open(go_to_url_tags, 'prj_u2');

																																																					 go_to_url_u3  ='$cur_php?mode=tdv&no=".$GR_Vals['no']."';																								
																																																					  window.open(go_to_url_u3, 'prj_u3');
																																							}
																					

																			 </script>
																			 
														<body onload=\"go_to_url();\">
																						
																</html>
										";
								}






}

	 

exit;






 ################### end of analysis_update_form #######################
}
################### end of analysis_update_form #######################








################### start of trade_review_lisT #######################
 function trade_reason_cmt_lisT($connect) {  # 장중 매매 종목 사유 입력
################### start of trade_review_lisT #######################
global $cur_php;
global $admin_info;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";

$dns_php="daily_news.php";

get_Permit($admin_info,"$cur_php?mode=trcl");

$GR_Vals=Get_Vals('mode');

$tr_type_array=array(0,"코멘트","매수","추가매수","익절","손절","마감");
$tr_type_img_array=array(0,"<img src='../img/bul59.gif'>","<img src='../img/buy.png'>","<img src='../img/buy.png'>","<img src='../img/sell.png'>","<img src='../img/sell.png'>","<img src='../img/heart2.gif'>");


	for($tt=1;$tt<count($tr_type_array);$tt++) {

												 if($tt==1) $chk_str="checked";
												 else $chk_str="";

												 $tr_type_str.="<input type=radio name='tr_type' value='".$tt."' $chk_str>".$tr_type_array[$tt]."";

		}


                    # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기


  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
			 $style_css

	       <script language=\"javascript\">

						 
																 function      get_max_tr_vol(stock_code){

																									 stock_no='tr_vol_'+stock_code;

																										// alert(stock_no);
																		
																								   const max_tr=document.getElementsByName(stock_no);

																								 max_value=0;

																									//   alert(max_tr.length);

																														for(var i=0;i<max_tr.length;i++)	{

																																						   chk_value=max_tr[i].value;

																																						 //  alert(chk_value);
																																						   
																																						  if(chk_value>max_value) {

																																							   max_value=chk_value;
																																	
																																					 }

																														 }

																										// comma_max_value=inputNumberFormat('tr_vol');

																										 comma_max_value = max_value.replace(/(\d)(?=(?:\d{3})+(?!\d))/g, '$1,');

																								   document.getElementById('tr_vol').value=comma_max_value;
														
																	                              }


																			function     go_to_url(stock_name,stock_code) {  // 

																																		//	stock_name=decodeURIComponent(stock_name);  // 받은걸 decode

																																			  go_to_url_tags  ='$dns_php?mode=gt&type=d4&key_word=\''+stock_name+'\'';
																								
																																			  window.open(go_to_url_tags, 'news_d4');

  																																			  go_to_url_t3  ='$dns_php?mode=gt&type=t3&key_word=\''+stock_name+'\'&stock_code=\''+stock_code+'\'             ';
																								
																																			  window.open(go_to_url_t3, 'news_t3');	

																																			 
																																			 go_to_url_t4  ='$cur_php?mode=ashv&stock_code='+stock_code;	
																																			  window.open(go_to_url_t4, 'news_t4');																																			

																																			 
																																			  

																							  											    go_to_urls_u5  ='https://new.infostock.co.kr/stockitem?code='+stock_code;         

																																			 //  alert(go_to_urls_u5);																								
																																			  window.open(go_to_urls_u5, 'news_d5');

																					}





						  function      real_time_str(rtime_vals) {	

																								   const date = new Date();
																									const year = date.getFullYear();
																									const month = ('0' + (date.getMonth() + 1)).slice(-2);
																									const day = ('0' + date.getDate()).slice(-2);
																									const hours = ('0' + date.getHours()).slice(-2);
																									const minutes = ('0' + date.getMinutes()).slice(-2);
																									const seconds = ('0' + date.getSeconds()).slice(-2);
																									const timeStr = hours + ':' + minutes + ':' + seconds

																									const dateStr = year + '-' + month + '-' + day +' '+timeStr;

																								   //alert(dateStr);

																								  document.getElementById('id_rtime').value=dateStr;

															                                     	}


							 function      inputNumberFormat(obj) {
								                                                                        str = String(obj.value);
																										str= str.replace(/[^\d]+/g, '');
																									    obj.value = str.replace(/(\d)(?=(?:\d{3})+(?!\d))/g, '$1,');
																								 }


							 function     submit_Confirm(v,chk_str) {		

																												 //  폼으로 넘어온 변수 이름과 값을 확인

																																					 if(0) {
																																						 
																																									 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																									return;
																																								}

																															   chk_vals=document.getElementById(chk_str).value;


																															   if(chk_vals=='' ) { 
																																											alert('내용없음'); 
																																											return;
																																								}  // 제목이 없으면 등록 취소


																																  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
																															      contents= document.getElementById('ir1').value;

																																								
																															// if (confirm(\"등록하시겠습니까?\")) {
																																  v.submit();
																																	
																														//		} 


																											} // end of submit_Confirm
     
       	 </script>
 

        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" >

        <table  align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\" width=".$tbl_width['i5t']."> 		";   # start of tbl 000


 #if(empty($GR_Vals['up_uDate'])){

   #     $today = date("Y-m-d");
 #} else $today=$GR_Vals['up_uDate'];


		 if($GR_Vals['stock_code']){

																$arr_no['qry']="SELECT * FROM `tbl_trade_reason_comment`  where stock_code='".$GR_Vals['stock_code']."' order by up_uDate desc limit 0,1 ";	

																$trade_no_array=php_mysql_Query($arr_no,$connect);

																$trade_value=$trade_no_array['value'][0];

															  $stock_buy_price=deco_txt($trade_value['stock_buy_price'],3,0);
															  $tr_vol=deco_txt($trade_value['tr_vol'],3,0);

															   $del_on=1;

												 }

      $cmt_tags=" 주도주는 무엇인가? 관찰하자.. \n  속도(틱20,틱60)를 보고, 900T 이상인 종목이 오늘의 주도주이다. \n  5일 고점 돌파 여부, 1분봉 20억이상, 5분봉 50억이상 나온 이후의 흐름을 보자";


        echo "<tr><td>";
     
				  										echo     "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px;'  width=100%> "; # start of 1번째  tbl

																	
																			
																			
																			echo  "<tr><td style='text-align:center;font-size:30px;font-weight:bold;height:60px;'>".$trade_wish."</td></tr>";




																																					echo  "<tr style='font-size:14px;'>
																																																																															

																																										<td> 
																																													<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																																		<input type=hidden name=mode  value='tru'>																																																	
																																																		<img src='../img/micon1.gif'> <a href='$cur_php?mode=trcl'>종목코드</a> &nbsp; 
																																																		 <input type='text' name='stock_code'  id='stock_code' value='".$GR_Vals['stock_code']."' size='3' maxlength='6'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																											
																																																		
																																																		 <img src='../img/micon1.gif'>  기준가격 <input type='text' name='stock_price'  id='stock_price' value='' size='5' maxlength='8' style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag  onkeyup='inputNumberFormat(this);'>		
																																																		 <img src='../img/micon1.gif'>  매수단가<input type='text' name='stock_buy_price'  id='stock_buy_price' value='".$stock_buy_price."' size='5' maxlength='8' style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag  onkeyup='inputNumberFormat(this);'>		
																																																		<img src='../img/micon1.gif'> 연관테마 	<input type='text'   id='thema_name_1' value='".$all_thema_name[$trade_value['thema_no']][1]."' size='15'  class=form_nc readonly  onclick=javascript:openclub2('daily_news.php?mode=thema_manaGe&opt=popup&key_word=".$thema_name."&id_no=1','width=700,height=1200','get_thema') style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																																																		<input type=hidden name='thema_no' id='thema_no_1'  value='".$trade_value['thema_no']."'>
																																																		
																																																		<input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_Confirm(document.myform,'id_rtime')\">
																																																																																															    
																																									   </td>
																																					</tr>";


																																			  			echo  "<tr style='font-size:14px;'>
																																																																															

																																										<td> 

																																											<img src='../img/micon1.gif'>  매도 호가수량 <input type='text' name='hoga_sell_qty'  id='hoga_sell_qty' value='' size='5' maxlength='8' style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag  onkeyup='inputNumberFormat(this);'>	

																																																		  	  <img src='../img/micon1.gif'>  3분봉최대 거래량 <input type='text' name='tr_vol'  id='tr_vol' value='".$tr_vol."' size='5' maxlength='8' style='font-size:20px;border-radius: 7px;border:dashed 1px orange;'  $auto_clear_tag onkeyup='inputNumberFormat(this);'>	
																																																			  
																																																			  <img src='../img/micon1.gif'>  배수 <input type='text' name='hoga_buy_qty'  id='tr_vol_rate' value='' size='5' maxlength='8' style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag  onkeyup='inputNumberFormat(this);'>	
																																																	    
																																									   </td>
																																								  </tr>

																																								  
																																								  <tr style='font-size:14px;'>
																																										<td>
																																										<input type='text' name='rtime'  id='id_rtime' value='' size='18'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> $tr_type_str
																																										</td>
																																									</tr>																																																																															  
																																								  
																																								  ";




		    $cat=array('cat_type'=>'tr_stg','cat_name'=>'tr_stg','disp'=>'select');

		    $get_chkbox_tag = get_chkbox_category($cat,$connect);

                                                                                                                                                        echo "<tr><td> $get_chkbox_tag</td></tr>";





	echo ("

  <tr style='font-size:14px;'>
																																										<td>																																										
																																											<textarea name=cmt style=\"width:780px; height:100px; overflow-x:hidden; overflow-y:auto;font-size:20px; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\"  $auto_clear_tag  onclick=\"real_time_str('".$rtime_str."');\">$cmt_tags</textarea>
																																										</td>
																																							</tr>

   <tr>
		<td colspan=4 align=center>								 
  ");

				# 시작 :스마트 에디터 불러오기
				echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
				echo"<textarea name=contents id=\"ir1\" style=\"width:755px; height:200px; display:none;\" $auto_clear_tag >그래프</textarea>";

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



                                                  echo "</table></td></tr>";



# 가장최근 날짜를 가져와라

    $dta['qry']="SELECT DATE_FORMAT(up_uDate,'%Y-%m-%d') as uDate  FROM `tbl_trade_reason_comment`  group by DATE_FORMAT(up_uDate,'%Y-%m-%d') desc  limit 0,5";																																													
	$dta['cur_day']=$GR_Vals['up_uDate'];
	$dta['urls']="$cur_php?mode=trcl&up_uDate=";


    $get_date_list= get_date_List($dta,$connect);

# 가장최근 날짜를 가져와라

    $arr_srch['qry']="SELECT * FROM `tbl_trade_reason_comment`  where DATE_FORMAT(up_uDate,'%Y-%m-%d')='".$get_date_list['today']."' order by up_uDate desc ";																																													
	$arr_srch['keys']='stock_code';
	$arr_srch['multi_keys']=1;
 	$trade_history_array=php_mysql_Query($arr_srch,$connect);


        echo "<tr><td>";
     
				  										echo     "<table style='border: 0px dashed orange; border-radius: 10px; border-spacing:10px;".$mobile_font_array['title']." padding:1px;'  width='100%'> "; # start of 1번째  tbl

														echo   "<tr><td colspan=6>".$get_date_list['tags']."</td></tr>";

														foreach($trade_history_array['multi_keys'] as $tha_key => $tha_value) {

														                                            echo "<tr><td> <table  style='border: 0px dashed orange; border-radius: 10px; border-spacing:5px;background-color:#EFF2FB;' border=0 width=100%>";

                                                                $get_stock=get_stock_info($tha_key,$connect);

																# print_r($get_stock);

																 $stock_info_str= " <span style='font-size:13px;'>거래량: ".deco_txt($get_stock['stock_vol']/10000,3,0)."만주  거래대금: ".deco_txt($get_stock['stock_vol_cap'],31,0)."억   시가총액 : ".deco_txt($get_stock['stock_cap'],3,0)."억    (회전율: ".deco_txt($get_stock['stock_vol_cap']/$get_stock['stock_cap']*100,132,50).") </span>";



															#	 go_to_url(stock_name,stock_code,key_str)

															echo "<tr><td colspan=7 height=40px; style='border-radius: 10px; border-spacing:3px;padding:10px;background-color:#A9D0F5;' width=95%>
															<img src='../img/go_on.gif'><a href='$cur_php?mode=trcl&stock_code=".$tha_key."&up_uDate=$today' onclick=\"go_to_url('".$get_stock['stock_name']."','".$tha_key."')\"><font  style='font-size:20px;font-weight:bold;'> ".$get_stock['stock_name']."</a>  ".deco_txt($get_stock['stock_rate'],13,0)."</font>  $stock_info_str</td></tr>";
															
															#echo "<tr><td><table  style='font-size:14px;' border=0>";

															

															                                            sort($tha_value);

																								foreach($tha_value as $thav_key => $thav_value) {

																									$nn++;


																									$today_str=explode(' ',$thav_value['up_uDate'])[1];

																									$max_width=$tbl_width['prj_u2']*0.1;
																									$contents=$thav_value['contents'];
																									$urls="$cur_php?mode=img_pop&type=trcl&no=".$thav_value['no']."";
																									$contents=base64_Img_decode($urls,$contents,$max_width);

																										echo "<tr style='font-size:14px;'><td rowspan=2 align=center width=80px; style='line-height:190%;' nowrap>(".$today_str.")
																										<br>".$tr_type_img_array[$thav_value['tr_type']]."</a></td>";

																										

																										if($thav_value['stock_buy_price'])  $stock_rate= "(".deco_txt($thav_value['stock_price']/$thav_value['stock_buy_price']-1.003,2,0).")";   else $stock_rate="";

																										if($del_on) $del_tags="<a href=\"$cur_php?mode=tru&del_no=".$thav_value['no']."&up_uDate=$today\"><img src='../img/ic/12-em-cross.png'></a>"; 


																							echo "																										
																										<td align=right>".deco_txt($thav_value['stock_price'],3,0)." 원 $stock_rate</td>
																										<td align=right>".deco_txt($thav_value['hoga_sell_qty'],3,0)." 주</td>																										
																										
																										<td align=right><input type=hidden name='tr_vol_".$tha_key."' value='".$thav_value['tr_vol']."'>".deco_txt($thav_value['tr_vol'],3,0)." 주</td>";
																										
																										if($thav_value['hoga_buy_qty']>0) echo "<td align=right>".deco_txt($thav_value['tr_vol']/$thav_value['hoga_buy_qty'],31,0)." </td>";


																										if($contents) echo "<td rowspan=2 align=center width=80px; style='line-height:190%;' nowrap>".$contents."</td>";



																							echo "
																										</tr>";

																										echo "<tr><td colspan=6 style='font-size:13px;color:blue;' width=100%><img src='../img/dot_b.gif'> ".$thav_value['cmt']." $del_tags</td></tr>";

																											if( $thav_value['thema_no']!=0) echo "<tr><td></td><td colspan=6 style='font-size:13px;color:red;' width=100%>&nbsp; <img src='../img/ico_thema.gif'> &nbsp; ".$all_thema_name[$thav_value['thema_no']][1]."</td></tr>";
																									
																									
																											echo "<tr>$dot_line</tr>";
																										}

												             		#	echo "</table></td></tr>";

															   echo "</table> </td></tr>";

														}

                    echo "</table>";

														   
													 
   echo "</td></tr>";




echo "</table>"; # end of tbl 000

echo "</body></html>";



 ################### end of trade_review_lisT #######################
}
################### end of trade_review_lisT #######################




################### start of trade_reason_cmt_UpdatE #######################
 function trade_reason_cmt_UpdatE($connect) { 
################### start of trade_reason_cmt_UpdatE #######################
global $cur_php;
require "./env/e.fnc";
$GR_Vals=Get_Vals('mode');

$GR_Vals['stock_price']=str_replace(',','',$GR_Vals['stock_price']);
$GR_Vals['stock_buy_price']=str_replace(',','',$GR_Vals['stock_buy_price']);
$GR_Vals['hoga_sell_qty']=str_replace(',','',$GR_Vals['hoga_sell_qty']);

$GR_Vals['tr_vol']=str_replace(',','',$GR_Vals['tr_vol']);


if($GR_Vals['del_no']) {

$query_del="delete  from tbl_trade_reason_comment where no='".$GR_Vals['del_no']."'";
$result_del=mysqli_query($connect,$query_del); 

			 Header("Location:$cur_php?mode=trcl");
			 exit;
 
}


if($GR_Vasl['tr_type']>3) $stock_buy_Price= 0;
else $stock_buy_price=$GR_Vals['stock_buy_price'];


#print_r($GR_Vals);
#exit;


 $contents=addslashes($GR_Vals['contents']);

$query_ins="insert into tbl_trade_reason_comment set contents='".$contents."', stock_code='".$GR_Vals['stock_code']."', stock_price='".$GR_Vals['stock_price']."', stock_buy_price='".$stock_buy_price."', cmt='".$GR_Vals['cmt']."', tr_type='".$GR_Vals['tr_type']."', hoga_sell_qty='".$GR_Vals['hoga_sell_qty']."', tr_vol_rate='".$GR_Vals['tr_vol_rate']."', tr_vol='".$GR_Vals['tr_vol']."', thema_no='".$GR_Vals['thema_no']."', up_uDate='".$GR_Vals['rtime']."' ";

	$result_ins=mysqli_query($connect,$query_ins); 



if($result_ins) {
			 Header("Location:$cur_php?mode=trcl");
		     
	        }

				else  echo "error";
 

#       mysqli_close($connect);





exit;



 ################### end of trade_reason_cmt_UpdatE #######################
}
################### end of trade_reason_cmt_UpdatE #######################













################### start of trade_reason_cmt_UpdatE #######################
 function category_manage($connect) { 
################### start of trade_reason_cmt_UpdatE #######################
global $cur_php;
require "./env/e.fnc";
$GR_Vals=Get_Vals('mode');

$test_on=0;

if($test_on) print_r($GR_Vals);


if($GR_Vals['cat_str']) {





	   if($GR_Vals['no']) 		   $arr_qry['qry']="update tbl_cat  set  cat_str='".$GR_Vals['cat_str']."' where no='".$GR_Vals['no']."' ";  # 수정


	   else {   
		   
		   				$query="select max(cat_ord_no) as max_no from tbl_cat where cat_type='".$GR_Vals['cat_type']."' ";
				       $result_max=mysqli_query($connect,"$query");
			           $max_num = mysqli_fetch_array($result_max, MYSQLI_ASSOC);
					   $max_ord_no=$max_num['max_no']+1;
				      
		   $arr_qry['qry']="insert into tbl_cat   set cat_type='".$GR_Vals['cat_type']."' ,   cat_str='".$GR_Vals['cat_str']."',cat_ord_no='".$max_ord_no."' ";     

	   }
		
		        $arr_qry['result']=1;

				if($test_on) print_r($arr_qry);
				else                php_mysql_Query($arr_qry,$connect);

}

# opt=up

if($GR_Vals['opt']=='up') {

					 $cur_ord_no= $GR_Vals['cat_ord_no'];
					 $new_ord_no= $cur_ord_no-1;

 	  			     $query="update tbl_cat set  cat_ord_no='$cur_ord_no'	 where cat_ord_no='$new_ord_no'";
                     mysqli_query($connect,$query);
					 
 	  			     $query="update tbl_cat set  cat_ord_no='$new_ord_no'	 where no='".$GR_Vals['no']."'";
                     mysqli_query($connect,$query);
				
}







if($GR_Vals['no']) {

				$arr_select=array('qry'=>"select * from tbl_cat where no ='".$GR_Vals['no']."'");
				if($test_on) print_r($arr_select);
				else             {  $cat_value_array=php_mysql_Query($arr_select,$connect); 
				                          $cat_value=$cat_value_array['value'][0];
				
				}

}


$arr_list=array('qry'=>"select * from tbl_cat group by cat_type ");
$cat_list= php_mysql_Query($arr_list,$connect);

 $cat_list_tags="<table><tr>";

  foreach($cat_list['value'] as $cl_key => $cl_value) {


	     if($cl_value['cat_type']==$GR_Vals['cat_type']) $sel_tags="<img src='../img/micon2.gif'> <font style='color:red;font-weight:bold;'>";


		 else $sel_tags="<a href='".$cur_php."?mode=cm&cat_type=".$cl_value['cat_type']."'  style='text-decoration-line: none;'>";

	      $cat_list_tags.="<td style='padding-right:25px;'>".$sel_tags.$cl_value['cat_type']."</td>";
	
  }

#	if($del_on) $del_tags="<a href=\"$cur_php?mode=tru&del_no=".$thav_value['no']."&up_uDate=$today\"><img src='../img/ic/12-em-cross.png'></a>"; 

 $cat_list_tags.="</tr></table>";


  echo "<html>

        <head>
             <title></title>
    
			 $style_css

	       <script language=\"javascript\">

						 
										 function     submit_Confirm(v,chk_str) {		

																												 //  폼으로 넘어온 변수 이름과 값을 확인

																															 if(0) {
																																						 
																																			 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																			return;
																																		}

																															 //  chk_vals=document.getElementById(chk_str).value;																															  																																																																						

																																  v.submit();

																											} // end of submit_Confirm
     
       	 </script>
 

        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" >

        <table  align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\" width=".$tbl_width['i3t']." > 		
		
		<tr><td>";  




echo "
	<table>

						<tr>
								<td width=60px;>  <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																	<input type=hidden name=mode  value='cm'>																																																	
																																	<input type=hidden name=no  value='".$GR_Vals['no']."'>																																																	
																																	<img src='../img/micon1.gif'> <a href='$cur_php?mode=cm' style='text-decoration-line: none;'>Type</a>
								</td>

								<td>
																																	 <input type='text' name='cat_type'  id='cat_type' value='".$GR_Vals['cat_type']."' size='6' maxlength='20'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>
																																	  <input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_Confirm(document.myform,'id_rtime')\">
								</td>
							</tr>
						<tr>

					    <tr>
							<td colspan=2>
														 $cat_list_tags
							</td>
						</tr>

							  <td> 
								<img src='../img/micon1.gif'> 내용
								</td>
								<Td>	
								 <input type='text' name='cat_str'  id='cat_str' value='".$cat_value['cat_str']."' size='40' maxlength='50'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>
								
								</td>

								</tr>
		</table>

		 ";


  echo "</td></tr>";

   if($GR_Vals['cat_type']) {
		  
		   $cat=array('cat_type'=>$GR_Vals['cat_type'],'disp'=>'list');

		    $get_chkbox_tag = get_chkbox_category($cat,$connect);
		   

	   }

  echo "<tr><td> <table><tr><td width=60px;></td><td>";


   echo       $get_chkbox_tag;





  echo "</td></tr></table></td></tr>";

  echo "</table></body></html>";





exit;











exit;



 ################### end of trade_reason_cmt_UpdatE #######################
}
################### end of trade_reason_cmt_UpdatE #######################








################### start of Get_analysis_Date #######################
function      Get_analysis_Date($unix_time,$Day_Arr) {

#calender_str(1,1,strtotime($as_value['uDate']))

			$one_day=3600*24;

			#$add_day=array(1,3,5,20); ## 1 -> 3 -> 5 -> 20

			$holi_add=0;
			$sales_day=1;

			$chk_uDate=$unix_time;


           $max_chk_day=max($Day_Arr)+20;


			for($i=1;$i<$max_chk_day;$i++)  {


				   $chk_uDate= $unix_time+$one_day*$i;
				    $chk_ptime=calender_str(1,0,$chk_uDate);

				  # echo $add_day[$i]."====";
				  # echo $chk_ptime['unix_str'];

				   $w=date("w",$chk_uDate);

				   if($w!=0 and  $w!=6)  {

			  
					 if(in_array($sales_day, $Day_Arr)) {

				            $chk_ptime=calender_str(1,0,$chk_uDate);
          #                 echo $chk_ptime['unix_str']."  ===   $sales_day\n";				  
						    $get_uDate[]=$chk_ptime['unix_str'];


					 }

					   
					   $sales_day++;

				   }
				  
  
				   

			}

		#	print_r($get_uDate);


			return  $get_uDate;
################### end of Get_analysis_Date #######################
}
################### end of Get_analysis_Date #######################





################### start of img_vieW #######################
function      img_vieW($connect) {

require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');

#var_dump($GR_Vals);




 if($GR_Vals['type']=='analysis') {

						  $arr_qry['qry']="select * from tbl_prj_analysis where no='".$GR_Vals['no']."'  ";
												

	  }

else if($GR_Vals['type']=='prj') {

							$arr_qry['qry']="SELECT * FROM `tbl_prj_yehior`  where prj_no='".$GR_Vals['prj_no']."'  ";  # limit 0,30


}

else if($GR_Vals['type']=='trade_review') {

							$arr_qry['qry']="SELECT * FROM `tbl_trade_review`  where no='".$GR_Vals['no']."'  ";  # limit 0,30


}

else if($GR_Vals['type']=='trcl') {

							$arr_qry['qry']="SELECT * FROM `tbl_trade_reason_comment`  where no='".$GR_Vals['no']."'  ";  # limit 0,30

}


else if($GR_Vals['type']=='top_grp') {

							$arr_qry['qry']="SELECT * FROM `tbl_daily_stock_vol_grp`  where no='".$GR_Vals['no']."'  ";  # limit 0,30
						

}

else if($GR_Vals['type']=='scrap_grp') {

							$arr_qry['qry']="SELECT * FROM `tbl_news_scrap_grp`  where no='".$GR_Vals['no']."'  ";  # limit 0,30
						

}

else if($GR_Vals['type']=='cmt_grp') {

							$arr_qry['qry']="SELECT * FROM `tbl_stock_cmt`  where no='".$GR_Vals['no']."'  ";  # limit 0,30
						

}




						  $get_pop=php_mysql_Query($arr_qry,$connect);

                          $pop_img_info=$get_pop['value'][0];
						   $contents=$pop_img_info['contents'];
						  $img_no=$GR_Vals['img_no'];

     

    

      base64_Img_popUp($contents,$img_no,$connect);



################### end of img_vieW #######################
}
################### end of img_vieW #######################






?>

