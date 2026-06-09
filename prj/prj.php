<?

require "../env/cnt.inc";

# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );

ini_set("allow_url_fopen",1);


#변수정의
$cur_php = basename($_SERVER['PHP_SELF']);
$admin_info=($_COOKIE['opt']);

# admin이 없으면 패스
if(!$admin_info['acc_permit']){ print_r($admin_info); exit; }

#변수정의
$mode = $_REQUEST["mode"];


if($mode=='if')              { prj_iFrame(); }

elseif($mode=='prj_list')              { Prj_lisT ($connect); }
elseif( $mode=='prj_write') prj_writE($connect);
elseif( $mode=='prj_view') prj_vieW($connect);

elseif( $mode=='task_write') task_writE($connect);
elseif( $mode=='task_view') task_vieW($connect);


elseif( $mode=='task_memo_update') task_memo_updatE($connect);


elseif($mode=='fuf')                                       { file_upload_form(); }

elseif($mode=='file_upload_server')        { file_upload_server(); }



else  {  

	  echo "	  <meta http-equiv=\"refresh\" content=\"0;url=../lo.php\"> ";
	
	//echo "<script language=\"javascript\">
    	//		alert(\" Version : $ver 모드가 없습니다. \");
    		//	</script>    			
    		//	";			
		}




############################################
function  prj_iFrame() {
###########################################
global $cur_php;
global $tbl_width;
require "../env/e.fnc";
require "../env/prj.fnc";
$GR_Vals=Get_Request_Post('mode');




$call_urls="$cur_php?mode=prj_list"; 


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


$tbl_prj_d2=$tbl_width['prj_d2t']+50;

echo "

  	<table height=100% width=100% border=0>
   
						   <tr valign=top>
													<Td width='".$tbl_width['prj_d1t']."' >
																<iframe src='$call_urls' id='res_frame_d1'    frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_d1' ></iframe>
													</td>

												<Td width='".$tbl_prj_d2."' >
																<iframe src='' id='res_frame_d2'    frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_d2' ></iframe>														
													</td>


												<Td width='".$tbl_width['prj_d3t']."'  valign=top>
																<iframe src='' id='res_frame_d3'   frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_d3' ></iframe>

												</td>

												<Td width='".$tbl_width['d4t']."'  valign=top>
																<iframe src='' id='res_frame_d4'   frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_d4' ></iframe>

												</td>
												
												<Td width='".$tbl_width['d5t']."'  valign=top>
																<iframe src='' id='res_frame_d5'   frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='prj_d5' ></iframe>

												</td>
											
								</tr>

 
								
							";

			echo "</table>";


										echo "</body></html>";



 ################### end of  prj_iFrame() #######################
}
################### end of  prj_iFrame() #######################




################### start of prj_list #######################
 function  prj_lisT($connect) { 
################### start of prj_list #######################
global $admin_info;
global $cur_php;

require "../env/prj.fnc";
require "../env/e.fnc";

get_Permit($admin_info,"$cur_php?mode=prj_list");

$arr_prj['qry']="SELECT * FROM `prj_name`  ";  # limit 0,30
$get_prj=php_mysql_Query($arr_prj,$connect);

  echo $style_css;

   echo "<table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98% >";

   echo "<tr><td colspan=4> &nbsp; <img src='../img/pen.gif'> <a href='$cur_php?mode=prj_write'>프로젝트등록</a></td></tr>";

   echo "<tr  align=center bgcolor=yellow height=40><td widht=20px;>No</td><td width=300>제목</td><td>시작</td><td>끝</td></tr>";

  foreach($get_prj['value' ] as $no => $prj) {

		 $start_day=date_str(13,1,strtotime($prj['sDate'])); # 날짜를 스트링으로 표시
 		 $end_day=date_str(13,1,strtotime($prj['eDate'])); # 날짜를 스트링으로 표시

		 #print_r($start_day);

## 등록된 종목리뷰 갯수
#	 $arr_analysis['qry']="SELECT no,uDate FROM `tbl_prj_analysis`  where prj_no='".$prj_news['prj_no']."'";  # limit 0,30
#  $get_prj_analysis=php_mysql_Query($arr_analysis,$connect);
#	$count_nums=count($get_prj_analysis['value']);
    
       echo "<tr height=40 align=center $font_tags>";
	   
	   echo "<td>".$prj['prj_no']."</td>";
	   echo  "<td align=left ><a href='$cur_php?mode=prj_view&prj_no=".$prj['prj_no']." ' target='prj_d2' $font_tags>".$prj['title']."</td>";

	   echo "<td>".$start_day['str']."</td><td>".$end_day['str']."</td>";
	   
	   
	   echo "</tr>";

  }

echo "</table>";


exit;


 ################### end of prj_list #######################
}
################### end of prj_list #######################






############################################
function prj_writE($connect) {
###########################################
require "../env/e.fnc";
require "../env/prj.fnc";


# 변수정의
global  $cur_php;
$GR_Vals=Get_Request_Post('mode');
$prj_no=$GR_Vals['prj_no'];


# 업데이트 또는 수정

if($GR_Vals['update']) {

		 $sDate=explode(' ',$GR_Vals['sDate'])[0];
		 $eDate=explode(' ',$GR_Vals['eDate'])[0];

		  $qry_vals="title='".$GR_Vals['title']."',sDate='".$sDate."',eDate='".$eDate."',contents='".$GR_Vals['contents']."'";

			  if($prj_no)         $prj_qry= "update prj_name set ".$qry_vals." where prj_no='".$prj_no."'" ;								 
			 else  			          $prj_qry= "insert into prj_name set ".$qry_vals;

    $result_qry=mysqli_query($connect,$prj_qry); 
	# echo $prj_qry;
  

#리스트로 보내기

  echo "<body onload=location.href='$cur_php?mode=prj_list'>";     

exit;


}

# $today_ptime=calender_str(1,1,strtotime($value['uDate']));

  # 수정 
  if($prj_no ) {
								  $arr_qry['qry']="select * from prj_name where prj_no='$prj_no' ";
								  $result=php_mysql_Query($arr_qry,$connect);     

								$prj_value=$result['value'][0];

								$default_cts=$prj_value['contents'];
					 
						  $sDate_str=calender_str(1,1,strtotime($prj_value['sDate']));
  						  $eDate_str=calender_str(1,1,strtotime($prj_value['eDate']));

						  $sDate=$sDate_str['unix_str'];
						  $eDate=$eDate_str['unix_str'];
					

						
	  }

	  else { 



          	  }





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
						 
    f.submit();	
	
      }

	 </script>



	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['prj_d2t'].">

        <table width=".$tbl_width['prj_d2t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


        <tr>
  
		            <td valign=top > 

		";

# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		

		
		<table width=100%  border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"prj_write\">
        <input type=\"hidden\" name=\"update\" value=1>
		<input type=\"hidden\" name=\"prj_no\" value=\"".$prj_no."\">

		      
			   <tr align=\"LEFT\" valign=\"MIDDLE\">
				  <td align=left style='padding-top:5px;font-size:12px;' nowrap>

					 <img src='../img/ic/ic_pen02.gif'> </font>프로젝트 이름
				  </td>
				  <td colspan=3>	            
					  <input type=text size='80' name='title' id='title' class=form_nc value=\"".$prj_value['title']."\">
				 </tr>

				 <tr align=\"left\">
				 <td></td>

					 <td align='left' style='padding-top:15px;' colspan=4>     
					<img src='../img/ic/6.gif' title='시작일'>
			     <input type='text' name='sDate'  id='sdate' value='$sDate' size='14' readonly class=form_nc onclick=\"check_mouse('myform.sdate','','0')\" style='cursor:hand'>
~
				 			     <input type='text' name='eDate'  id='edate' value='$eDate' size='14' readonly class=form_nc onclick=\"check_mouse('myform.edate','','0')\" style='cursor:hand'>

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

												 var urls='../smart_editor/insert_attach.php?mode=get_dir_file&dir_st='+Up_Dir+'';
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


 ################### end of prj_write_form #######################
}
################### end of prj_write_form #######################




################### start of prj_vieW #######################
 function  prj_vieW($connect) { 
################### start of prj_vieW #######################
global $cur_php;

require "../env/prj.fnc";
require "../env/e.fnc";

$GR_Vals=Get_Request_Post('mode');

$arr_report['qry']="SELECT * FROM `prj_name`  where prj_no='".$GR_Vals['prj_no']."' ";  # limit 0,30
$get_prj=php_mysql_Query($arr_report,$connect);

 $prj_value=$get_prj['value'][0];
 $contents= $prj_value['contents'];

# 컨텐츠에서 이미지 추출하기
$max_width=$tbl_width['prj_d2t']*0.95;
$urls="$cur_php?mode=img_pop&type=prj&prj_no=".$prj_value['prj_no']."";
$contents=base64_Img_decode($urls,$contents,$max_width);

#print_r($arr_report);

echo "<html><body>";

echo $style_css;

   
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



echo "        <table width=".$tbl_width['prj_d2t']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  ><tr><td> ";  ## start of table 000 


       echo "<table    style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -001


	    echo "<Tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:20px;color:blue; height:40px;' align=center ><td></td><td> ".$prj_value['title']."  </td></tr>";

       echo "<Tr><td colspan=2>".$contents."</td></tr>";

      echo "</table>";    ## end of table 000 -001

	  echo "</td></tR>";



	  echo "<tr><td>";  ## 하단 모니터링 리스트 

	   echo "<table border=0   style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -002

			   echo "<tr><td style='font-size:13px;' colspan=2> <img src='../img/pen.gif'><a href='$cur_php?mode=task_write&prj_no=". $prj_value['prj_no']." '>업무등록</td></tr>";

                    $arr_prj_task['qry']="SELECT  task_no,prj_no,title,uDate FROM `prj_task`  where prj_no='".$GR_Vals['prj_no']."'  order by task_no desc    ";  # limit 0,30																																			
				    $get_prj_task=php_mysql_Query($arr_prj_task,$connect);

					  ###### start of if																														
                   					 if($get_prj_task['value']) {

										 



																																							 echo "<tr><td colspan=2>";

																																							 echo "<table>";

																																							 echo "<Tr><td>no</td><td>제목</td><td>Date</td></tr>";


																																																			  
																																																									  # start of foreach 001
																																																											 foreach($get_prj_task['value'] as $no_key => $task_value) {

																																																																 $base_day_str= calender_str(3,13,$task_value['uDate']);

																																																																	 # $max_width=50;
																																																																	#	$urls="$cur_php?mode=img_pop&type=analysis&no=".$ga_value['no']."";
																																																																	#	$contents=base64_Img_decode($urls,$ga_value['contents'],$max_width);
																																																																	$nn++;

																																																																 echo "<tr>";
																																																																 echo "<td>".$nn."</tD>";
																																																																 echo "<td ><a href='$cur_php?mode=task_view&task_no=".$task_value['task_no']."' target='prj_d3'>".$task_value['title']."</a></td>";
																																																																  echo "<td style='font-size:13px;' width=90 align=center>".$base_day_str['unix_str']."</td>";


																																																																  echo "</tr>";
																																																												 } 
																																																											# end of foreach 001


																																							echo "</table></td></tr>";

																																																				

																																																	 
																																		

																																								  } 
																																				  #### end of if




	   echo "</table>";  ## end of table 000 -002

	  echo "</td></tr>";  # 하단 모니터링 리스트



echo "</td><tr></table>";  # end of table 000 

echo "</body></html>";



exit;



 ################### end of prj_vieW #######################
}
################### end of prj_vieW #######################


############################################
function task_writE($connect) {
###########################################
require "../env/e.fnc";
require "../env/prj.fnc";

# 변수정의
global  $cur_php;
$GR_Vals=Get_Request_Post('mode');
$no=$GR_Vals['no'];

$test_on=0;


# 업데이트 또는 수정

if($GR_Vals['update']) {

		 $uDate=explode(' ',$GR_Vals['uDate'])[0];

		  $qry_vals="title='".$GR_Vals['title']."',uDate='".$uDate."',contents='".$GR_Vals['contents']."',prj_no='".$GR_Vals['prj_no']."'";

			  if($no)         $prj_qry= "update prj_task set ".$qry_vals." where no='".$no."'" ;								 
			 else  			          $prj_qry= "insert into prj_task set ".$qry_vals;

   if(!$test_on) $result_qry=mysqli_query($connect,$prj_qry); 
   else { echo $prj_qry; exit; }
  

#리스트로 보내기

  echo "<body onload=location.href='$cur_php?mode=prj_view&prj_no=".$GR_Vals['prj_no']."'>";     

exit;


}

# $today_ptime=calender_str(1,1,strtotime($value['uDate']));

  # 수정 
  if($no ) {

							  $arr_qry['qry']="select * from prj_task where no='$no' ";
							  $result=php_mysql_Query($arr_qry,$connect);     

							  $task_value=$result['value'][0];

							  $default_cts=$task_value['contents'];
				 
						  $uDate_str=calender_str(1,1,strtotime($task_value['uDate']));
					
	  }

	  else { 
		  $today_ptime=calender_str(1,0,time());
          	  }

$uDate=$today_ptime['unix_str'];



  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title></title>
    
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
						 
    f.submit();	
	
      }

	 </script>



	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" width=".$tbl_width['prj_d3t'].">

        <table width=".$tbl_width['prj_d3t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->


        <tr>
  
		            <td valign=top > 

		";

# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		

		
		<table width=100%  border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"task_write\">
        <input type=\"hidden\" name=\"update\" value=1>
		<input type=\"hidden\" name=\"prj_no\" value=\"".$GR_Vals['prj_no']."\">

		      
			   <tr align=\"LEFT\" valign=\"MIDDLE\">
				  <td align=left style='padding-top:5px;font-size:12px;' nowrap>

					 <img src='../img/ic/ic_pen02.gif'> </font>제목
				  </td>
				  <td colspan=3>	            
					  <input type=text size='60' name='title' id='title' class=form_nc value=\"".$task_value['title']."\">
				
				<img src='../img/ic/6.gif' title='시작일'>
			     <input type='text' name='uDate'  id='udate' value='$uDate' size='14' readonly class=form_nc onclick=\"check_mouse('myform.udate','','0')\" style='cursor:hand'>

				 			    

				<input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>                    
								 <img src='../img/cafe_unlock.gif' title='* 비밀번호를 입력하지 않으면 글을 수정하거나 삭제하실 수 없습니다.'>
								 <input type=\"Password\" name=\"usrpwd\" value=\"".$task_value['usrpwd']."\" size=\"8\" maxlength='8' class=form_nc>          
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
				echo"<textarea name=contents id=\"ir1\" style=\"width:755px; height:312px; display:none;\">$default_cts</textarea>";

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

												 var urls='../smart_editor/insert_attach.php?mode=get_dir_file&dir_st='+Up_Dir+'';
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


 ################### end of prj_write_form #######################
}
################### end of prj_write_form #######################




################### start of prj_vieW #######################
 function  task_vieW($connect) { 
################### start of prj_vieW #######################
global $cur_php;

require "../env/prj.fnc";
require "../env/e.fnc";

$GR_Vals=Get_Request_Post('mode');

$cur_tbl_width=$tbl_width['prj_d3t'];

$arr_task['qry']="SELECT * FROM `prj_task`  where task_no='".$GR_Vals['task_no']."' ";  
$get_task=php_mysql_Query($arr_task,$connect);
 $task_value=$get_task['value'][0];
 $contents= $task_value['contents'];

# 컨텐츠에서 이미지 추출하기
$max_width=$cur_tbl_width*0.95;
$urls="$cur_php?mode=img_pop&type=task&task_no=".$task_value['task_no']."";
$contents=base64_Img_decode($urls,$contents,$max_width);


$today=calender_str(1,1,time());												  
$uDate_str=$today['unix_str'];

$up_Base_Dir="../dta/prj/memo/".$today['yyyy'];


echo "<html><body>";

echo $style_css;

   
  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

echo "<html>

        <head>
             <title>task_view</title>
    
			 $style_css



	<script language=\"javascript\">
     
			 
			 function      memo_chkfrm(f) {	         
					    
     	//  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
       //   contents= document.getElementById('ir1').value;

		
	if (f.mcts.value== '') {
		alert('내용을 입력하여 주십시오');
		return false;
	}
						 
    f.submit();	
	
      }


       // 업데이트 디렉토리와 이름구분자를 변수로 받아서 insert_attach.php 파일에 넘겨줌.
 function      upload_file(up_dir,str) {
		                                       
												var Up_Dir = up_dir;
					                            var popup_X = event.screenX;	
												 var popup_Y = event.screenY;

												 var urls='$cur_php?mode=fuf&dir_st='+Up_Dir+'&str='+str+'';
											     var zz;

											     zz = window.open(urls, 'newWindow', 'width=400, height=130,left='+popup_X+',top='+popup_Y);

												  zz.focus();

											}




	   function      upload_file_inner_html(tit,urls,no) {

																							  // alert(tit);
																							  // alert(urls);
																							  // return;

																							   document.getElementById('attach_title').value= tit;
																							   document.getElementById('attach_urls').value= urls;
														   
															
														}




	 </script>
	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\"  onLoad='document.myform.title.focus();'>";



echo "        <table width=".$cur_tbl_width." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  ><tr><td> ";  ## start of table 000 


       echo "<table    style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -001


	    echo "<Tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:20px;color:blue; height:40px;' align=center ><td></td><td> ".$task_value['title']."  </td></tr>";

       echo "<Tr><td colspan=2>".$contents."</td></tr>";

      echo "</table>";    ## end of table 000 -001

	  echo "</td></tR>";



 echo "<tr><td>";  ## 하단 모니터링 리스트 

	   echo "<table border=0   style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -002

#			   echo "<tr><td style='font-size:13px;' colspan=2> <img src='../img/pen.gif'><a href='$cur_php?mode=task_write&prj_no=". $prj_value['prj_no']." '>업무등록</td></tr>";

echo "
			  									 <form method=post action=".$cur_php." enctype='multipart/form-data' name=myform>
												  <input type=\"hidden\" name=\"mode\" value=\"task_memo_update\">
  												  <input type=\"hidden\" name=\"memo_no\" value=\"".$task_memo['memo_no']."\">
												  <input type=\"hidden\" name=\"task_no\" value=\"".$task_value['task_no']."\">  												  
												  <input type=\"hidden\" name=\"prj_no\" value=\"".$task_value['prj_no']."\">

		";

echo "

													  <Td width=80><img src='../img/pen.gif'>Comment</td>
													  												  
														 <td>
															 
															 <img src='../img/ic/6.gif' title='일자'>
                                                			    <input type='text' name='uDate'  id='uDate' value='$uDate_str' size='14' readonly class=form_nc onclick=\"check_mouse('myform.uDate','')\" style='cursor:hand'>
															
															 &nbsp; &nbsp; &nbsp; &nbsp;  <input type=button value='등록' onclick=\"javascript:memo_chkfrm(document.myform);\" class=form_nc style='width:60px;'>       
															 
															 <a onclick=\"upload_file('$up_Base_Dir','".$task_memo['task_no']."');\" style='cursor:hand'><img src='../img/sweety/8-em-heart.png'>첨부</a>:: <input type='text' id='attach_title' name='attach_title' size=30 class=form_nc>

														</tr>


														 <tr>	
														 <td width=80></td>
														 <td colspan=3>
															   <textarea name=mcts id=\"mcts\" style=\"width:680px; height:70px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc $auto_clear_tag >".$memo_modify['mcts']."</textarea>
															
															   </td>
														 </tr>

														<tr align=left>															
															<td style='font-size:12px;'></td>
															<td>															
															<input type='hidden' id='attach_urls' name='attach_urls'>
															$attach_file_modify_memo_tag<span id='itemListm'></span></td>
														</tr>	

												 </table></form>$calender_js";

echo "</td></tr>   ";
# 끝: 메모 쓰기
     
	   echo "</table>";  ## end of table 000 -002

	  echo "</td></tr>";  # 하단 모니터링 리스트


 echo "<tr><td>";  ## 하단 메모 리스트 

	   echo "<table border=0   style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=98%>"; ## start of table 000 -002

	     $arr_prj_task_memo['qry']="SELECT  * FROM `prj_task_memo`  where task_no='".$GR_Vals['task_no']."'  order by memo_no desc    ";  # limit 0,30																																			
		$get_prj_task_memo=php_mysql_Query($arr_prj_task_memo,$connect);

		#print_r($get_prj_task_memo);

	  echo "<tr><td>no</td><td colspan=2>제목</td><td>Date</td>";

if($get_prj_task_memo['value' ]) {

									  foreach($get_prj_task_memo['value' ] as $no => $task_memo) {

										  $nn++;

										  if($task_memo['attach_file']) $attach_file_tag= attach_file_Display($task_memo['attach_file'],0);
										  $memo_uDate=calender_str(3,13,$task_memo['uDate']);												  
										  
										  echo "<tr><td>".$nn."</td><td>".$task_memo['mcts']."</td><td>  ".$attach_file_tag."</td><td>".$memo_uDate['unix_str']."</td>";

											# print_r($task_memo);

									  }

} # end of value

#			   echo "<tr><td style='font-size:13px;' colspan=2> <img src='../img/pen.gif'><a href='$cur_php?mode=task_write&prj_no=". $prj_value['prj_no']." '>업무등록</td></tr>";

echo "</table></td></tr>"; # 하단 메모리스트



echo "</td><tr></table>";  # end of table 000 

echo "</body></html>";



exit;



 ################### end of task_vieW #######################
}
################### end of task_vieW #######################





################### start of Memo_update #######################
 function  task_memo_updatE($connect) { 
################### start of Memo_update #######################

require "../env/e.fnc";
# 변수정의
global  $cur_php;
$GR_Vals=Get_Request_Post('mode');


$test_on=0;


#print_r($GR_Vals);


   $uDate=explode(' ',$GR_Vals['uDate'])[0];


   $attach_file.=addslashes($GR_Vals['attach_title'])."##^*^##".$GR_Vals['attach_urls'];


		  $qry_vals="uDate='".$uDate."',mcts='".$GR_Vals['mcts']."',task_no='".$GR_Vals['task_no']."',prj_no='".$GR_Vals['prj_no']."',attach_file='$attach_file' ";

			  if($prj_no)         $prj_qry= "update prj_task_memo set ".$qry_vals." where memo_no='".$GR_Vals['memo_no']."'" ;								 
			 else  			          $prj_qry= "insert into prj_task_memo set ".$qry_vals;

if(!$test_on)    $result_qry=mysqli_query($connect,$prj_qry); 
else  echo $prj_qry;


if(!$test_on) {
			 Header("Location: $cur_php?mode=task_view&task_no=".$GR_Vals['task_no']."");
	        }

exit;


if(!$mypims_memo['no']) {
				$query="select max(no) as no from mypims_memo";
				$result=mysqli_query($connect,"$query");
#				$mypims['no']=mysqli_result($max_num,"no")+1;  // 가장 최근의 글이 지워지면.. 지워진 글부터 번호를 부여함.
                #$max_num=mysqli_fetch_array($result); 


				if($result) { $max_num = mysqli_fetch_array($result, MYSQLI_ASSOC);
				              $mypims_memo['no']=$max_num['no']+1;
				            }

				  else $mypims_memo['no']=1;

						$no_ins=1; # 번호가 없으면 값을 0으로 줘서, 데이터 삽입으로 이동

				} else { $no_ins=0; }





$mypims_memo['attach_file']=attach_file_mng($mypims_memo['upfile'],$mypims_memo['upfile_chk'],$mypims_memo['upfile_chk_hidden'],$mypims_memo['upfile_old_display_name'],$mypims_memo['upfile_new_display_name'],$mypims_memo['no']);

$mypims_memo['mtitle']= preg_replace("/tempUpFile/", "".$mypims_memo['no']."",$mypims_memo['mtitle'],-1,$count); # 임시파일명이 발견된다면

$skip_Array=array('upfile','upfile_chk','upfile_chk_hidden','upfile_old_display_name','upfile_new_display_name','rtime');


# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($mypims_memo as $pims_key => $pims_value) { # start of cust_val		 


  if(in_array($pims_key,$skip_Array)) continue;

  if($pims_key == 'cycle_data') $pims_value=trim($pims_value);
  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="rtime='$rtime'";
# 끝: 받은 자료를 가지고 쿼리로 만듬


if($no_ins)   $query="insert into mypims.mypims_memo set $up_qry"; 
else  $query="update mypims_memo set  $up_qry where no=".$mypims_memo['no']; 

echo $query;

#exit;

$result=mysqli_query($connect,"$query");                      



if($result) {
			 Header("Location: mypims.php?mode=read&pims_no=".$mypims_memo['pims_no']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

       mysql_close($connect);

#echo $query;
#exit;



################### end   of Memo_update #######################
} 
################### end   of Memo_update #######################





##############################################################
function file_upload_form() {
##############################################################
# 		
require "../env/e.fnc";
require "../env/inf.fnc";
global $cur_php;

  #변수할당
 $get_file=Get_Request_Post('mode');

 
#print_r($get_file);

  # 변수 할당

# <파일설명><INPUT type='text' name='fname'  id='fname' onfocus=\"select();\" style='width:200' class='form_nc'>
echo "
<html STYLE=\"width:100%; height: 100%; \">
<head><title>파일 첨부하기</title><head>
      <meta charset='utf-8'>
$style_css

</HEAD>

<BODY scroll=no>
<table height=100% width=100% border=\"0\" cellspacing=\"0\" cellpadding=\"0\" >

			<tr><td align=center>
										<table border=\"0\" cellspacing=\"2\" cellpadding=\"0\" style='font-size:12px;' width=100% >
																 <form name=\"insert_attach\" method=\"post\" enctype=\"multipart/form-data\" > 
																 <input type=\"hidden\" name=\"mode\" value='file_upload_server'>
																 <input type=\"hidden\" name=\"no\" value='".$get_file['no']."'>
																 <input type=\"hidden\" name=\"dir_st\" value='".$get_file['dir_st']."'>
																 <input type=\"hidden\" name=\"str\" value='".$get_file['str']."'>

											<tr>								
														<td><INPUT type='file' name='local_file'  onfocus=\"select();\" style='width:300' class='form_nc'></td>
														<td align=left width=150px;><input type='submit' class='form_nc' value='첨부'  style='width:70'>		</td>
											</tr>
											
											
										
										</table>
					 </form>

			</td></tr>
</table>


</BODY>
</HTML>";

###################################
} # insert_img
###################################




###########################################################
function file_upload_server() { # 파일을 서버에
###########################################################
require "../env/e.fnc";

  #변수할당

#print_r ($_POST);

  $get_file=Get_Request_Post('mode');

#echo "err";

print_r($get_file);

  # 변수 할당

#exit;

$tmp_name=$_FILES['local_file']['tmp_name'];

$thumb_width=300;

$_FILES['local_file']['name']= preg_replace("/\s\s+/","",$_FILES['local_file']['name']);


$Ext_Array = explode(".",$_FILES['local_file']['name']); 
$Ext_Array_Num=count($Ext_Array);
$Hdr_Name_Str= $Ext_Array[$Ext_Array_Num-2];
$Ext_Str= $Ext_Array[$Ext_Array_Num-1];

$cur_date=date("Y_m_d_His");


$file_str="news";
if($get_file['str']) $file_str=$get_file['str'];



$new_file_name=$file_str."_".$cur_date.".".$Ext_Str;	 # 번호 할당은 실제 db 업데이트할때 함						
	
if(!$tmp_name) { exit; }

if(!$get_file['fname']) $display_name=$Hdr_Name_Str;
	else $display_name=$get_file['fname'];


$file_ext_info=chk_file_icon($Ext_Str); # 이미지 여부 체크  [0] 아이콘이미지, [1] 이미지여부  1: 이미지
$Is_Img_File=$file_ext_info[1];

$Make_Attach_File_Tag=$file_ext_info[1]."@@@".$Ext_Str."@@@".$display_name."@@@".$new_file_name."@@@".$get_file['dir_st']."/";

							 # 파일확장자 - 원래이름 - 서버에 올려질때 이름 
										 
								 $uploadDir=$get_file['dir_st'];  # 폴더 체크해서 없으면 새로 만들고..
#								 copy();


		if(!is_dir($uploadDir)){
		
					 @mkdir($uploadDir, 0777,true);

									if(is_dir($uploadDir)) {
																					@chmod($uploadDir, 0777);
																					echo "$uploadDir  디렉토리를 생성하였습니다.";
																				}

								 else {
																									echo "$uploadDir 디렉토리를 생성하지 못했습니다.";
																				 }

			}




  		#	   $uploadDir= str_replace('..',".",$uploadDir); ## 이상하게 업로드 디렉토리에 .. 이 붙으면 오류가 남.
		@move_uploaded_file($tmp_name,"$uploadDir/$new_file_name");

        $upload_urls=substr($uploadDir,2)."/".$new_file_name;


if(0) {
          
			echo "Info:::<Br> $display_name<br> $Make_Attach_File_Tag  <br> $upload_urls<br><Br>";

			echo "<a href='$upload_urls'>$display_name</a>";

			exit;
}

 

echo "
<script>
 //   alert(1);
	var tmpOpener = window.opener;  // opener정의
  // alert(2);
	 tmpOpener.upload_file_inner_html('".$display_name."','".$upload_urls."','".$get_file['no']."');    			   		
	//     alert(3);
	 self.close();
</script>	 
	";		   
					   
exit;
##############################################################
} # end of Up_File_Script
##############################################################




################### start of attach_file_mng #######################
function attach_file_Display($attach_file,$opt) {
################### start of attach_file_mng #######################

#$File_Array=explode("&^^^&",$attach_file);

#$j=500;



#  Attach_List_Tag='pasteHTM(\' <img src='+ Up_File_Array[4] +' width= '+ Up_File_Array[5] +'  height= '+ Up_File_Array[6] +'>  \');'; 
#  if(Up_File_Array[0]==1) {
#  Attach_List_Tag='pasteHTM(\' <a onclick=DownLoad_Attach(' + Up_File_Array[4] + ')> 다운로드:' + Display_File_Name + '</a> \');'; 			
# <input type=hidden name=upfile_chk_hidden[] value=\"'+count+'\" checked><input type=\"button\" onclick=\"'+ Attach_List_Tag +'\" value=\''+ Display_File_Name +'\' class=form_nc>


#foreach ($attach_file as $file_key => $file_value){

         $attach_Array=explode("##^*^##",$attach_file);
				    
                  $ext_array=explode('.',$attach_Array[1]); 
				  $ext=$ext_array[count($ext_array)-1];

							$ext_ico=chk_file_icon($ext);

							$attach_tag.="<table><tr><td style=font-size:12px;><img src='../img/ext/$ext_ico[0]'> <a href='..".$attach_Array[1]."' target='_blank'>$attach_Array[0]</a></td></tr></table>";	 		 
        #                     else     $attach_tag.="<table><tr><td style=font-size:12px;><img src='../img/ext/$ext_ico[0]'> <a href='mypims.php?mode=file_download&num=$file_key&pims_no=$pims_no'>$attach_Array[2]</a></td></tr></table>";	 		 

						  			 
		 #}

							

return $attach_tag;




################### end of attach_file_mng #######################
}
################### end of attach_file_mng #######################



?>