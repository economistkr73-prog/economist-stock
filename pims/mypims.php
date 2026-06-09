<?php
require "../env/cnt.inc";

# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );

ini_set("allow_url_fopen",1);

#변수정의
$mode = $_REQUEST["mode"];
#변수정의

#변수정의
$cur_php = basename($_SERVER['PHP_SELF']);
$admin_info=($_COOKIE['opt']);

if(!$admin_info['acc_permit']) { echo "404 Error";  exit;}

if($mode=='write')              { mypims_write ($connect); }

elseif($mode=='read')              { mypims_Read ($connect); }

elseif($mode=='update')         { mypims_update($connect); }

elseif($mode=='prg')         { mypims_prg($connect); }

elseif($mode=='delete')         { mypims_delete($connect); }

elseif($mode=='memo_update')         { mypims_Memo_update($connect); }

elseif($mode=='memo_prg')         { mypims_Memo_prg($connect); }

elseif($mode=='memo_delete')         { mypims_Memo_delete($connect); }

elseif($mode=='prj_write')              { mypims_Prj_write ($connect); }

elseif($mode=='prj_read')              { mypims_Prj_Read ($connect); }

elseif($mode=='prj_update')         { mypims_Prj_update($connect); }

elseif($mode=='prj_Get_update')         { mypims_Prj_Get_Update($connect); }

elseif($mode=='prj_delete')         { mypims_Prj_delete($connect); }


elseif($mode=='id_list')              { mypims_Id_list ($connect); }
elseif($mode=='id_write')              { mypims_Id_write ($connect); }
elseif($mode=='id_view')               { mypims_Id_vieW ($connect); }
elseif($mode=='id_delete')               { mypims_Id_delete ($connect); }
elseif($mode=='id_update')             { mypims_Id_update ($connect); }


elseif($mode=='fd_src_list')              { mypims_fd_src_list ($connect); }
elseif($mode=='fd_src_write')              { mypims_fd_src_write ($connect); }
elseif($mode=='fd_src_update')              { mypims_fd_src_update ($connect); }
elseif($mode=='fd_src_view')              { mypims_fd_src_view ($connect); }



elseif($mode=='pims_no_id_update')             { mypims_pims_no_id_update ($connect); }


elseif($mode=='id_grp_write')   { mypims_Id_grp_writE($connect); }

elseif($mode=='file_download')     { mypims_File_Download($connect); }


else  {  echo "<script language=\"javascript\">
    			alert(\" Version : $ver \");
    			</script>    			
    			";			
		}

mysqli_close($connect);


############################################
function mypims_write($connect) {
###########################################
global $cur_php;


require "../env/e.fnc";
require "../env/inf.fnc";

$GR_Vals=Get_Request_Post('mode');

  # 변수 할당
 

  # 번호가 있다면.. 게시물 내용을 불러올 것
  $checked_tag=array("","","","","","");

  $chked_str=array(0,"checked","checked","checked","checked","checked");

 
  if(is_array($mypims) && $mypims['pims_no'] ) {

      $query="select * from mypims_cycle where pims_no=".$mypims['pims_no'];
      $result=mysqli_query($connect,"$query");     

      if($result) $value = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";

       $checked_tag[$value['cycle_type']]= $chked_str[$value['cycle_type']];

       $checked_holiday_tag[$value['holiday_type']]= $chked_str[$value['holiday_type']];

	   $mypims['board_type']=$value['cycle_type'];

   	   $rtime=calender_str(1,0,$value['rtime']);

	   #$attach_num=$value['pims_no'];

  	    $yy=date("y",$value['rtime']);


      # 첨부파일 불러오기
      $attach_file_tag=attach_file_Display($value['attach_file'],$value['pims_no'],1,0);



	  }

else { 
	    
		if($mypims['unixtime']) $today=$mypims['unixtime']; else $today=time();



 	    $mm=date("m",$today);
        $dd=date("d",$today);

 	    $yy=date("y",$today);

		$value['cycle_data']="$mm/$dd";

        $value['prj_no']=$mypims['prj_no'];

		$rtime=calender_str(1,0,$today);	

		#$attach_num="99999999";
	  

      }
  
$rtime_str=$rtime['unix_str'];


$opt[name]='cat';
$opt[box]='radio';
$opt[id_str]='cat';
$opt[value]=$value['cat'];
$category=get_category("mypims",$opt,$connect);


 # 게시판 타입
 #  board_type=0 ;; 일반업무 1: 반복업무
 #  

 
if($mypims['board_type']==0) { # 일반업무

$base_Date_Str="<tr><td></td><td><table style='font-size:9pt;'  align=left><tr><td>
				 <img src='../img/ic/6.gif' title='시작일'>
			     <input type='text' name='rtime'  id='rtime' value='$rtime_str' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime','','1')\" style='cursor:hand'></td></tr></table></td></tr>";

$cycle_type_tags="<tr><td><input type=\"hidden\" name=\"cycle_type\" value=\"0\"></td></tr>"; 

}

else {

		$base_Date_Str="<input type='hidden' name='rtime'  id='rtime' value='$rtime_str'>";
		
		$cycle_type_tags="
		
		<tr style='font-size:9pt;'  height=40>
		<td align=left><img src='../img/ic/ic_star_b.gif'> 기 준 일</td>
		
		<td>

			 <table style='font-size:9pt;'>

				<tr height=40>
					<td colspan=4><input type=\"text\" name=\"cycle_data\" value=\"".$value['cycle_data']."\" size='5' class=form_nc></td>
			    </tr>
		
		  	<tr height=40>
  
			<td><input type=\"radio\" name=\"cycle_type\" value=\"1\" $checked_tag[1]></td><td>기념일(양력)</td>
			<td><input type=\"radio\" name=\"cycle_type\" value=\"2\" $checked_tag[2]></td><td>기념일(음력)</td>		
			
			<td><input type=\"radio\" name=\"cycle_type\" value=\"3\" $checked_tag[3]></td><td>1년에 한번</td>
			<td><input type=\"radio\" name=\"cycle_type\" value=\"4\" $checked_tag[4]></td><td>매월 특정일</td>

            <td><input type=\"radio\" name=\"cycle_type\" value=\"5\" $checked_tag[5]></td><td>매월 말일</td>
						
			</tr>
			</table></td></tr>


			<tr style='font-size:9pt;'  height=40>
		<td align=left><img src='../img/ic/ic_star_b.gif'> 휴일처리</td>
		
		<td>

		<table style='font-size:9pt;' align=left>

		<tr>
  			<td><input type=\"radio\" name=\"holiday_type\" value=\"1\" $checked_holiday_tag[1]></td><td>휴일무시</td>
		  <td><input type=\"radio\" name=\"holiday_type\" value=\"2\" $checked_holiday_tag[2]></td><td>휴일이전처리(금)</td>
			<td><input type=\"radio\" name=\"holiday_type\" value=\"3\" $checked_holiday_tag[3]></td><td>휴일이후처리(월)</td>
			<td><input type=\"radio\" name=\"holiday_type\" value=\"4\" $checked_holiday_tag[4]></td><td>주말처리(일)</td>
        </tr>


		</table></td></tr>
			";

	}

  

   




  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>mypims</title>
    
			 $style_css

			 $calender_js

	<script language=\"javascript\">
     
			 
			 function chkfrm(f) {	         
				
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

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=900 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td> 
        ";


# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("	  
		<form method=post action=\"mypims.php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"update\">
		<input type=\"hidden\" name=\"pims_no\" value=\"".$value['pims_no']."\">
		<input type=\"hidden\" name=\"prj_no\" value=\"".$value['prj_no']."\">
			
		<table width=\"800\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>


   <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_pen02.gif'> </font>제 목
      </td>
      <td colspan=3>	            
   		  <input type=text size='80' name='title' id='title' class=form_nc value=\"$value[title]\">
     </tr>
	  

 <tr align=\"left\">
 <td></td>

     <td align='left' style='padding-top:15px;' colspan=4>     

	   <img src='../img/ico_tag.gif'><input type=text size='65' name='tags' value=\" $value[tags]\" class=form_nc>

	 		  &nbsp; &nbsp; &nbsp; <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>                    
				 <img src='../img/cafe_unlock.gif' title='* 비밀번호를 입력하지 않으면 글을 수정하거나 삭제하실 수 없습니다.'>
				 <input type=\"Password\" name=\"usrpwd\" value=\"".$value['usrpwd']."\" size=\"15\" maxlength='8' class=form_nc>          
                             
     </td>
	</tr>

");


echo $base_Date_Str;


echo "
   <tr  height=40 style='font-size:9pt;'>
		<td align=left><img src='../img/ic/ic_star_b.gif'> 분 류</td>
		
		<td>
       <table style='font-size:9pt;'  align=left><tr><td>";

  echo $category['cat_name_select'];

 echo "</td></tr></table></td></tr>";


echo $cycle_type_tags;


	echo ("

   <tr>
		<td colspan=4>
  ");


# 시작 :스마트 에디터 불러오기
echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
echo"<textarea name=contents id=\"ir1\" style=\"width:766px; height:512px; display:none;\">$value[contents]</textarea>";

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

echo "</td></tr>";

## 이미지를 불러오고 삽입하는 함수



 # 기본 이미지 사이즈는 insert_attach.php 에서 조정이 가능

echo "
      <script language=\"javascript\">


       // 업데이트 디렉토리와 이름구분자를 변수로 받아서 insert_attach.php 파일에 넘겨줌.
       function InSeRt_AttAch(up_dir) {
		                                       
												var Up_Dir = up_dir;
												ret = showModalDialog('insert_attach.php?mode=get_dir_file&dir_st='+Up_Dir, window , \"resizable: no; help: no; status: no; scroll: no; \");
											}

				count=0;

				
   function attach_file_fnc(Display_File_Name,File_Str_Array) {
                  	        		                   
 					var Up_File_Array = Array();
					Up_File_Array = File_Str_Array.split('&%&');  // [0]이미지파일여부 [1] 확장자 [2] 표시이름 [3] 실제이름 [4] 서버 경로 [5] 가로 [6] 세로

				 if(Up_File_Array[0]==1) {
						      
							Attach_List_Tag='pasteHTM(\' <img src='+ Up_File_Array[4] +Up_File_Array[3]+' width= '+ Up_File_Array[5] +'  height= '+ Up_File_Array[6] +'>  \');'; 			
													
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

                function pasteHTM(input_str) {

					// alert(input_str);
					oEditors.getById[\"ir1\"].exec(\"PASTE_HTML\", [input_str]);
					
				}

			   
	  </script>
";

  $up_Base_Dir="../pims/dta/$yy"; 

  #  다운로드는 파일명.. 디렉토리 번호를 넘겨준후에.. 모달창에서 다운로드 받도록 함. 다운로드 할수 있도록 함.

 ## 이미지를 불러오고 삽입하는 함수




echo "  

			<tr align=left height=40>
				<td style='font-size:12px;'><a onclick=\"InSeRt_AttAch('$up_Base_Dir');\" style='cursor:hand'><img src='../img/sweety/8-em-heart.png'>첨부</a>:: </td><td>$attach_file_tag<span id='itemList'></span></td>
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
      </tr>

	  	
      </table>  <!-- start of table 000 -->
      ";


echo "</td><tr>

</table>";


 ################### end of write_form #######################
}
################### end of write_form #######################




############################################
function mypims_Read($connect) {
###########################################
require "../env/e.fnc";

  #변수할당
  $mypims=Get_Post_Get_Value('mode');
  $id_view_no=$mypims['id_no'];
  # 변수 할당

  # 번호가 있다면.. 게시물 내용을 불러올 것
 
      $query="select * from mypims_cycle where pims_no=".$mypims['pims_no'];
      $result=mysqli_query($connect,"$query");     

      if($result) $value = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";

       $checked_tag[$value['cycle_type']]= $chked_str[$value['cycle_type']];

       $checked_holiday_tag[$value['holiday_type']]= $chked_str[$value['holiday_type']];

	   $mypims['board_type']=$value['cycle_type'];


   $attach_file_str=attach_file_Display($value['attach_file'],$value['pims_no'],0,0); 

$attach_file_tag="<tr><td colspan=4 style='padding-top:8px;padding-left:15px;padding-right:8px;' valign=top><table width=100%><Tr><td valign=top>$attach_file_str</td></tr></table></td></tr>";


$opt[name]='cat';
$opt[box]='radio';
$opt[id_str]='cat';
$opt[value]=$value['cat'];
$category=get_category("mypims",$opt,$connect);

# 

 # 게시판 타입
 #  board_type=0 ;; 일반업무 1: 반복업무
 #  


  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>mypims</title>    
			 $style_css
			 			 $calender_js
		</script>

	";

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=900 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td> 
        ";



 if($value['prj_no']) {

      $query="select * from mypims_prj where prj_no=".$value['prj_no'];
      $result=mysqli_query($connect,"$query");     

      if($result) $prj = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";

	 $prj_str="( 프로젝트: <a href='mypims.php?mode=prj_read&prj_no=".$prj['prj_no']."'><img src='../img/ic/star_red.gif'> ".$prj['prj_tit']."</a> )";

 }


if($value['end_time']){

        	$end_day_str=date_str(1,0,$value['end_time']);
	        $end_time_str="<a href='#' onclick=\"myconfirm('mypims.php?mode=prg&end_time=0&pims_no=".$value['pims_no']."','완료된 업무를 진행중으로 변경하시겠습니까?'); return false;\" >(완료된 일자: ".$end_day_str['str'].")</a>";
         }

else { $end_time=time(); 
	  $end_time_str="<a href='mypims.php?mode=prg&end_time=$end_time&pims_no=".$value['pims_no']."'><img src='../img/ic/29.gif' title='완료여부'> 완료처리하기</a>"; 
	  }

# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

 # 첨부파일 추가하는 것때문에. 상단에 폼이 있어야 함
echo "<form method=post action=\"mypims.php\" enctype='multipart/form-data' name=myform>";


echo "					
	<table width=\"800\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";




echo "
   <tr align=\"LEFT\" valign=\"MIDDLE\" align=left >
      <td >
         <img src='../img/ic/ic_pen02.gif'> </font>제 목
      </td>
      <td colspan=2 width=520>	                  ". $value['title']."   $prj_str </td> <td> $end_time_str</td> </tr>
	  

 <tr align=\"left\">
 <td></td>

     <td align='left' style='padding-top:15px;' colspan=4 class=form_nc>     

	   <img src='../img/ico_tag.gif'> ".$value['tags']."
                            
     </td>
	</tr>
";


     echo "
   <tr  height=40 style='font-size:9pt;'>
   <td></td>
		<td>
       <table style='font-size:9pt;'  cellspacing=\"0\" cellpadding=\"1\" align=left><tr class=form_nc>";

if($cycle_type_array[$value['cycle_data']]) {	   
	echo"<td style='border:dashed 1px orange;padding-left:8px;padding-right:8px;padding-top:8px;'> <img src='../img/ic/ic_star_b.gif'> 기준일 :: ".$value['cycle_data']."</tD>";
}

 echo "<td width=20></td>
	   <td style='border:dashed 1px orange;padding-left:8px;padding-right:8px;padding-top:8px;padding-bottom:8px;'> <img src='../img/ic/ic_star_b.gif'> 분 류 :: ";

 echo $category['cat_logo'][$value['cat']];


if($cycle_type_array[$value['cycle_type']]) {

 echo " </td> <td width=20></td> <td style='border:dashed 1px orange;padding-left:8px;padding-right:8px;padding-top:8px;'> <img src='../img/ic/ic_star_b.gif'> 반복여부 :: "   .$cycle_type_array[$value['cycle_type']]." </td><td width=20></td><td style='border:dashed 1px orange;padding-left:8px;padding-right:8px;padding-top:8px;'> <img src='../img/ic/ic_star_b.gif'> 휴일처리 ::
 ".$holiday_type_array[$value['holiday_type']]."</td>";

}

echo"
 </tr>		   
 </table></td></tr>";

######################## 시작 :관련인 찾기

      $query_pims_no_id="select * from mypims_pims_no_id where pims_no=".$value['pims_no'];
      $result_pims_no_id=mysqli_query($connect,"$query_pims_no_id");     

	while($pims_no_id = mysqli_fetch_array($result_pims_no_id, MYSQLI_ASSOC)){

		        $query_id="select * from mypims_id where id_no=".$pims_no_id['id_no'];
                $result_id=mysqli_query($connect,"$query_id");
				
				$idcard = mysqli_fetch_array($result_id, MYSQLI_ASSOC);


				if($idcard['id_no']==$id_view_no) $id_cts_tag="<tr><td colspan=2 ><img src='../img/ic/27.gif'> ".$idcard['id_email']."</td> <td><img src='../img/ic/ic_pen02.gif' onclick=\"openclub2('mypims.php?mode=id_write&id_no=".$idcard['id_no']."&pims_no=".$value['pims_no']."','width=500,height=950','id_list')\" style='cursor:hand'>수정하기</td></tr>
				<tr><td class=form_nc2 colspan=3>".$idcard['id_cts']."</td> </tr>";
					else $id_cts_tag="";

					if($id_view_no>0) $id_view_no=0;
					else $id_view_no= $idcard['id_no'];

				$idcard_tag.="<tr><td>[".$idcard['id_corp']."] <a href='mypims.php?mode=read&pims_no=".$value['pims_no']."&id_no=".$id_view_no."'>".$idcard['id_name']." (".$idcard['id_title'].") </a></td><td> <img src='../img/ic/ic_hp.gif'> ".$idcard['id_hp']."</td><td>(유선:".$idcard['id_phone'].")</td></tr> $id_cts_tag";	}


############################### 끝: 관련인 찾기


echo " <tr align=\"left\">
 <td></td>

     <td align='left' style='padding-top:15px;' colspan=3>     

            <table style='font-family:굴림,Seoul,arial,helvetica; color:#417295; font-size:12px;' width=100% border=0>
			        <tr>
					    <td valign=top><img src='../img/ic/ic_meet.gif'> <a href=javascript:openclub2('mypims.php?mode=id_list&pims_no=".$value['pims_no']."','width=500,height=950','id_list')>관련인</a><td>
						<td><table style='font-family:굴림,Seoul,arial,helvetica; color:#417295; font-size:12px;'  width=100%>$idcard_tag</table></td>
					</tr>
			</table>


     </td>
	</tr>";


 


echo"<tr><td colspan=4 style='padding-top:8px;padding-left:15px;padding-right:8px;' valign=top><table class=form_nc2 width=100%><Tr height=500><td valign=top>".$value['contents']."</td></tr></table></td></tr>";

echo $attach_file_tag;


echo "   	 				

   <tr align=\"CENTER\" valign=\"MIDDLE\">
   <td colspan=4 style='font-size:12px;'>  
   | <a href='../index.php'>닫기</a> | &nbsp; &nbsp; | <a href='mypims.php?mode=write&pims_no=".$mypims['pims_no']."'>수정</a> | &nbsp; &nbsp; | <a href='#' onclick=\"myconfirm('mypims.php?mode=delete&pims_no=".$mypims['pims_no']."','삭제 하시겠습니까?'); return false;\" >삭제</a> |

	</td>
	</tr>

 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 


# 시작: 메모 쓰기



if($mypims['no']) {

  $query_memo_modify="select * from mypims_memo where no=".$mypims['no'];
  $result_modify=mysqli_query($connect,"$query_memo_modify");    
  $memo_modify = mysqli_fetch_array($result_modify, MYSQLI_ASSOC);

    $memo_tit=$memo_modify['mtitle'];

    $rtime=calender_str(1,0,$memo_modify['rtime']);	 
  
         # 첨부파일 불러오기
    $attach_file_modify_memo_tag=attach_file_Display($memo_modify['attach_file'],$memo_modify['no'],1,1);

 }

  else {
    $memo_tit="제목";
    $rtime=calender_str(1,0,time());	 

    if($mypims['ref_no']=="") $mypims['ref_no']=0;
	$memo_modify['ref_no']=$mypims['ref_no'];

    $auto_clear_tag="onBlur=\"checkField(this)\" onFocus=\"clearField(this)\"";

	$attach_file_modify_memo_tag="";

  }

  $yy=date("y",$rtime['mktime']);

$rtime_str=$rtime['unix_str'];

if($memo_modify['ref_no']>0) { $memo_title_img="../img/ic/dot_bb.gif";  if(!$mypims['no'])$memo_tit="연관글";  }
else { $memo_title_img="../img/ic/16-circle-red.png";    }

echo "  
<tr style='font-size:9pt;' height=20><tr><td colspan=4> ";


echo "
			                                 	<table style='font-size:10pt;' width=800>
																								  
												  <input type=\"hidden\" name=\"mode\" value=\"memo_update\">
  												  <input type=\"hidden\" name=\"no\" value=\"".$mypims['no']."\">
												  <input type=\"hidden\" name=\"pims_no\" value=\"".$value['pims_no']."\">
  												  <input type=\"hidden\" name=\"pims_title\" value=\"".$value['title']."\">
												  <input type=\"hidden\" name=\"prj_no\" value=\"".$value['prj_no']."\">
  												  <input type=\"hidden\" name=\"ref_no\" value=\"".$memo_modify['ref_no']."\">

												<script language=\"javascript\">
								 
										 
																	 function chkfrm(f) {	         


														  if (f.mtitle.value == '' || f.mtitle.value == '제목')
																			  {
																		alert('제목 입력해주세요');
																		f.mtitle.focus();
																		return;
																		  }
															
														if (f.mcts.value == '') {
																alert('내용을 입력하여 주십시오');
																f.mcts.focus();
																return false;
															}
																		
															f.submit();	
															
														  }


													
																			   // 업데이트 디렉토리와 이름구분자를 변수로 받아서 insert_attach.php 파일에 넘겨줌.
																	function InSeRt_AttAch(up_dir) {
																													   
																														var Up_Dir = up_dir;
																														ret = showModalDialog('insert_attach.php?mode=get_dir_file&dir_st='+Up_Dir, window , \"resizable: no; help: no; status: no; scroll: no; \");
																													}

																						count=0;

																						
																	function attach_file_fnc(Display_File_Name,File_Str_Array) {
																															   
																						var Up_File_Array = Array();
																						Up_File_Array = File_Str_Array.split('&%&');  // [0]이미지파일여부 [1] 확장자 [2] 표시이름 [3] 실제이름 [4] 서버 경로 [5] 가로 [6] 세로

																										count++;

																						var newSpanItem = document.createElement(\"span\"); 
																						newSpanItem.setAttribute(\"id\",count); 

																						var msg_str = '<table><tr><td style=font-size:12px;><input type=checkbox name=upfile_chk[] value=\"'+count+'\" checked><input type=hidden name=upfile_chk_hidden[] value=\"'+count+'\" checked>'+ Display_File_Name +'<input type=hidden name=upfile[] value=\''+File_Str_Array+'\'></td></tr></table>';
																												
																						newSpanItem.innerHTML = msg_str; 	    
																							
																							var itemListNode = document.getElementById('itemListm'); 
																							itemListNode.appendChild(newSpanItem); 
																				}

																	

												 </script>";


  $up_Base_Dir="../pims/dta/$yy/memo"; 

echo "

													  <Td width=80><a href='mypims.php?mode=read&pims_no=".$value['pims_no']."'><img src='../img/pen.gif'></a>Comment</td>
														 <td><img src='$memo_title_img'><input type=text size='55' name='mtitle' id='mtitle' value='$memo_tit' class=form_nc $auto_clear_tag style='text-align:left;'>
															 &nbsp; &nbsp;
															 
															 <img src='../img/ic/6.gif' title='일자'>
                                                			     <input type='text' name='rtime'  id='rtime' value='$rtime_str' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime','')\" style='cursor:hand'>
															
															 &nbsp; &nbsp; &nbsp; &nbsp;  <input type=button value='등록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;'>                    

														</tr>


														 <tr>	
														 <td width=80></td>
														 <td colspan=3>
															   <textarea name=mcts id=\"mcts\" style=\"width:680px; height:88px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc $auto_clear_tag >".$memo_modify['mcts']."</textarea>
															
															   </td>
														 </tr>

														<tr align=left>															

															<td style='font-size:12px;'><a onclick=\"InSeRt_AttAch('$up_Base_Dir');\" style='cursor:hand'><img src='../img/sweety/8-em-heart.png'>첨부</a>:: </td>
															<td>$attach_file_modify_memo_tag<span id='itemListm'></span></td>
														</tr>	

												 </table></form>";


echo "</td></tr>   ";
# 끝: 메모 쓰기



# 시작:메모가 1개 이상 있다면..

  $query_memo="select * from mypims_memo where pims_no=".$value['pims_no']." and ref_no='0' order by rtime";
  $result_memo=mysqli_query($connect,"$query_memo");               

  $memo_prg=array("12-em-check.png","완료");


 if(mysqli_num_rows($result_memo)>0) { # start of if num_rows


echo "   <tr style='font-size:9pt;'><tr><td colspan=4>
         <table width=100% style='font-family:굴림,Seoul,arial,helvetica; color:#417295; font-size:12px; border:2px solid; padding-left : 2px;  border:0px dashed;border-left-color:white; border-right-color:white;'><tr><td height=1 colspan=4 align=right style='border:1px dashed;border-top-color:orange;border-bottom-color:white;border-left-color:white; border-right-color:white;'></tr> ";
	
	while($mypims_memo = mysqli_fetch_array($result_memo, MYSQLI_ASSOC)){

			$memo_day=date_str(1,0,$mypims_memo['rtime']);	


            $attach_file_memo_str=attach_file_Display($mypims_memo['attach_file'],$mypims_memo['no'],0,1); 

            $attach_file_memo_tag="<tr><td></td><td colspan=2 style='padding-top:8px;padding-left:15px;padding-right:8px;' valign=top>$attach_file_memo_str</td></tr>";
  
			if($mypims_memo['end_time']==0) {				
												$prg_strike_tag="";  $end_time_str="";
												$end_time_onclick="onclick=\"check_mouse('myform.end_time','mypims.php?mode=memo_prg&end_time_prg=1&no=".$mypims_memo['no']."&pims_no=$mypims_memo[pims_no]&cal_str=')\"";			
									         }

			else { 				  
						  $prg_strike_tag="<strike style='color:gray'>";  
						  
						  $memo_end_day=date_str(1,0,$mypims_memo['end_time']);
						  $end_time_str= "<td><img src='../img/ic/ic_bell.gif'><font style='color:blue;'> ".$memo_end_day['str']."&nbsp;  완료</td>";

						  $end_time_onclick="onclick=\"myconfirm('mypims.php?mode=memo_prg&end_time_prg=2&no=".$mypims_memo['no']."&pims_no=$mypims_memo[pims_no]','완료된 업무를 초기화 하시겠습니까??'); return false;\"";				  
				  }


            # 제목을 보여줄것인지 아닌지.. 

			if($mypims['view_no']==$mypims_memo['no']) { $mcts_display_tag="<tr><td></td><td style='padding-right : 10px;padding-left : 10px;line-height:180%'>".nl2br($mypims_memo['mcts'])."</td></tr>"; $view_on=1; 
			                $view_on_img="";
		
			     }
			else { $mcts_display_tag="";  $view_on=0; $attach_file_memo_tag="";  $view_on_img="<a href='mypims.php?mode=read&pims_no=".$value['pims_no']."&view_no=".$mypims_memo['no']."'><img src='../img/ic/9.gif' title='펼쳐보기'></a>"; }

			  $url="mypims.php?mode=memo_prg&no=".$mypims_memo['no']."&pims_no=".$mypims_memo['pims_no']."&cal_str=";
          	     
	echo "<tr height=40><td width=75><img src='../img/ic/16-clock.png' title='날짜 변경' onclick=\"check_mouse('myform.rtime','$url')\" style='cursor:hand'>".$memo_day['str']."</a><br>(<a href='mypims.php?mode=read&pims_no=".$value['pims_no']."&ref_no=".$mypims_memo['no']."'><img src='../img/ic/12-em-pencil.png' title='연관글 쓰기'></a>)  $view_on_img </td>
		<td><img src='../img/ic/16-circle-red.png' title='완료여부' $end_time_onclick style='cursor:hand;'></a> $prg_strike_tag <a href='mypims.php?mode=read&pims_no=".$value['pims_no']."&view_no=".$mypims_memo['no']."' title='내용보기'>".$mypims_memo['mtitle']."</a> 
		<a href='mypims.php?mode=read&no=".$mypims_memo['no']."&pims_no=".$mypims_memo['pims_no']."'><img src='../img/ic/13.gif' title='수정하기'></a><img src='../img/ic/12-em-cross.png' title='메모 삭제하기'style='cursor:hand' onclick=\"myconfirm('mypims.php?mode=memo_delete&no=".$mypims_memo['no']."&pims_no=".$mypims_memo['pims_no']."','삭제 하시겠습니까?'); return false;\"></strike> $end_time_str</td></tr>
		";

		echo $mcts_display_tag;

		echo $attach_file_memo_tag;

 

# 연관글이 있으면 따로 불러냄
       $query_ref_memo="select * from mypims_memo where ref_no=".$mypims_memo['no']." order by rtime";
       $result_ref_memo=mysqli_query($connect,"$query_ref_memo");  

       $ref_memo_num=mysqli_num_rows($result_ref_memo);
	
			if($ref_memo_num>0) { # start of if num_rows

                  	while($mypims_ref_memo = mysqli_fetch_array($result_ref_memo, MYSQLI_ASSOC)){

							$memo_ref_day=date_str(1,0,$mypims_ref_memo['rtime']);	

							$attach_file_ref_memo_str=attach_file_Display($mypims_ref_memo['attach_file'],$mypims_ref_memo['no'],0,1); 

							$attach_file_ref_memo_tag="<tr><td></td><td colspan=2 style='padding-top:8px;padding-left:15px;padding-right:8px;' valign=top>$attach_file_ref_memo_str</td></tr>";


							if($mypims_ref_memo['end_time']==0) {
				
												$prg_strike_ref_tag="";  $end_time_ref_str="";
												$end_time_ref_onclick="onclick=\"check_mouse('myform.end_time','mypims.php?mode=memo_prg&end_time_prg=1&no=".$mypims_ref_memo['no']."&pims_no=$mypims_memo[pims_no]&cal_str=')\"";
			
									         }

			else { 				  
						  $prg_strike_ref_tag="<strike style='color:gray'>";  
						  
						  $memo_end_ref_day=date_str(1,0,$mypims_ref_memo['end_time']);
						  $end_time_ref_str= "<td><img src='../img/ic/ic_bell.gif'><font style='color:blue;'> ".$memo_end_ref_day['str']."&nbsp; 완료</td>";

						  $end_time_ref_onclick="onclick=\"myconfirm('mypims.php?mode=memo_prg&end_time_prg=2&no=".$mypims_ref_memo['no']."&pims_no=$mypims_memo[pims_no]','완료된 업무를 초기화 하시겠습니까??'); return false;\"";				  
				  }


              if($view_on)		 $mcts_ref_display_tag="<tr><td></td><td style='padding-right : 10px;padding-left : 10px;line-height:180%'>".nl2br($mypims_ref_memo['mcts'])."</td></tr>";
			  else { $mcts_ref_display_tag=""; $attach_file_ref_memo_tag=""; }


 		    $url_ref="mypims.php?mode=memo_prg&no=".$mypims_ref_memo['no']."&pims_no=".$mypims_memo['pims_no']."&cal_str=";

          	$memo_ref_str.="<tr height=40><td width=75><img src='../img/ic/16-clock.png' title='날짜 변경' onclick=\"check_mouse('myform.rtime','$url_ref')\" style='cursor:hand'>".$memo_ref_day['str']."</a></td>
		<td><img src='../img/ic/dot_bb.gif' title='완료여부' $end_time_ref_onclick style='cursor:hand'></a> $prg_strike_ref_tag <a href='mypims.php?mode=read&no=".$mypims_ref_memo['no']."&pims_no=".$mypims_memo['pims_no']."'>".$mypims_ref_memo['mtitle']."</a> 
		<img src='../img/ic/12-em-cross.png' title='메모 삭제하기'style='cursor:hand' onclick=\"myconfirm('mypims.php?mode=memo_delete&no=".$mypims_ref_memo['no']."&pims_no=".$mypims_memo['pims_no']."','삭제 하시겠습니까?'); return false;\"></strike> $end_time_ref_str</td></tr>
		$mcts_ref_display_tag
		$attach_file_ref_memo_tag
		";

		
		}

          #   $ref_num_tag="* 총 $ref_memo_num 개의 연관글이 있습니다.";



   		} 


	
		# end of if num_rows
# 연관글이 있으면 따로 불러냄


       echo "<tr><td></td><td colspan=3><table table width=100% style='font-family:굴림,Seoul,arial,helvetica; color:#417295; font-size:12px; border:2px solid; padding-left : 2px;  border:0px dashed;border-left-color:white; border-right-color:white;'><tr><td height=1 colspan=4 align=right style='border:1px dashed;border-top-color:orange;border-bottom-color:white;border-left-color:white; border-right-color:white;'>$memo_ref_str</table></td></tr>";


		echo "	  <tr align=\"LEFT\" valign=\"MIDDLE\"> 
		  					    <td height=1 colspan=4 align=right style='border:1px dashed;border-top-color:orange;border-bottom-color:white;border-left-color:white; border-right-color:white;'></td>
		</tr>


		";


       $ref_num_tag="";
	   $memo_ref_str="";

  	}

echo "</table></td></tr>";

  }  # end of if num_rows
# 끝: 메모가 1개 이상 있다면..


echo "  </table>  <!-- start of table 000 -->  ";

echo "</td><tr></table>";

echo "</body></html>";


 ################### end of write_form #######################
}
################### end of write_form #######################


################### start of update #######################
 function  mypims_update($connect) { 
################### start of update #######################

require "../env/e.fnc";


#시작: 변수정의
	$up_qry="";
	$mypims=Get_Post_Get_Value('mode');
#	$rtime=time();
# 끝: 변수정의

#print_r($mypims);
#exit;


$rtime_array=calender_str(2,0,$mypims[rtime]);

$rtime=$rtime_array['mktime'];

if(!$mypims['pims_no']) {
				$query="select max(pims_no) as pims_no from mypims_cycle";
				$result=mysqli_query($connect,"$query");

				if($result) { $max_num = mysqli_fetch_array($result, MYSQLI_ASSOC);
				              $mypims['pims_no']=$max_num['pims_no']+1;
				            }

				  else $mypims['pims_no']=1;

						$no_ins=1; # 번호가 없으면 값을 0으로 줘서, 데이터 삽입으로 이동

				} else { $no_ins=0; }


#첨부파일 관리
$mypims['attach_file']=attach_file_mng($mypims['upfile'],$mypims['upfile_chk'],$mypims['upfile_chk_hidden'],$mypims['upfile_old_display_name'],$mypims['upfile_new_display_name'],$mypims['pims_no']);

if($mypims['prj_no']=="") $mypims['prj_no']=0;

$mypims['contents']= preg_replace("/tempUpFile/", "".$mypims['pims_no']."",$mypims['contents'],-1,$count); # 임시파일명이 발견된다면

$mypims['contents']=addslashes($mypims['contents']);


 $skip_Array=array('upfile','upfile_chk','upfile_chk_hidden','upfile_old_display_name','upfile_new_display_name','upfile','rtime','pims_no');


# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($mypims as $pims_key => $pims_value) { # start of cust_val		 

  if(in_array($pims_key,$skip_Array)) continue;
  if($pims_key == 'cycle_data') $pims_value=trim($pims_value);
  if($pims_value=="") $pims_value=0;

  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="rtime='$rtime'";
# 끝: 받은 자료를 가지고 쿼리로 만듬




if($no_ins)   $query="insert into mypims.mypims_cycle set $up_qry"; 
else 
	 { $query="update mypims_cycle set  $up_qry where pims_no=".$mypims['pims_no'];  

       $query_memo="update mypims.mypims_memo set  pims_title='".$mypims['title']."' where pims_no=".$mypims['pims_no']; 

	    $result_memo=mysqli_query($connect,"$query_memo");

     }

#echo $query;
#exit;

 $result=mysqli_query($connect,"$query");                      


if($result) {
			 Header("Location: ../pims/mypims.php?mode=read&pims_no=".$mypims['pims_no']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

       mysql_close($connect);

#echo $query;
#exit;


################### end   of update #######################
} ################### end   of update #######################
################### end   of update #######################


################### start of attach_file_mng #######################
function attach_file_mng($upfile,$upfile_chk,$upfile_chk_hidden,$upfile_old_display_name,$upfile_new_display_name,$no) {
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


################### start of attach_file_mng #######################
function attach_file_Display($attach_file,$pims_no,$opt,$memo) {
################### start of attach_file_mng #######################

$File_Array=explode("&^^^&",$attach_file);

$j=500;



#  Attach_List_Tag='pasteHTM(\' <img src='+ Up_File_Array[4] +' width= '+ Up_File_Array[5] +'  height= '+ Up_File_Array[6] +'>  \');'; 
#  if(Up_File_Array[0]==1) {
#  Attach_List_Tag='pasteHTM(\' <a onclick=DownLoad_Attach(' + Up_File_Array[4] + ')> 다운로드:' + Display_File_Name + '</a> \');'; 			
# <input type=hidden name=upfile_chk_hidden[] value=\"'+count+'\" checked><input type=\"button\" onclick=\"'+ Attach_List_Tag +'\" value=\''+ Display_File_Name +'\' class=form_nc>


foreach ($File_Array as $file_key => $file_value){

         $attach_Array=explode("&%&",$file_value);

         if(count($attach_Array)>1) {	
			 $j=$j+1;



            if($opt==1) {       
				                 

           				      

				                  
								 if($attach_Array[0]==1) $Attach_List_Tag="pasteHTM('<img src=\'$attach_Array[4]$attach_Array[3]\' width= \'$attach_Array[5]\'  height= \'$attach_Array[6]\'>');"; 
								 else  $Attach_List_Tag="pasteHTM('<a onclick=DownLoad_Attach(\'$attach_Array[4]$attach_Array[3]\');> 다운로드: $attach_Array[3]</a>')"; 			

								 if($memo==1) $insert_cts_tag="";
			                     else  $insert_cts_tag="<input type=\"button\" onclick=\"$Attach_List_Tag\" value='본문삽입' class=form_nc>";

								 $attach_tag.="<table><tr><td style=font-size:10px;><input type=checkbox name=upfile_chk[] value=\"$j\" checked><input type=hidden name=upfile_chk_hidden[] value=\"$j\" checked></td><td>
								              $insert_cts_tag<input type=\"hidden\" value='$attach_Array[2]' class=form_nc name='upfile_old_display_name[]'><input type=\"text\" value='$attach_Array[2]' class=form_nc name='upfile_new_display_name[]' size=30>
											 <input type=hidden name=upfile[] value='".$file_value."'>
											 </td></tr></table>";	 		 
							}

					else  {
						    
							$ext_ico=chk_file_icon($attach_Array[1]);

							if($memo==1)  $attach_tag.="<table><tr><td style=font-size:12px;><img src='../img/ext/$ext_ico[0]'> <a href='mypims.php?mode=file_download&num=$file_key&pims_no=$pims_no&memo=1'>$attach_Array[2]</a></td></tr></table>";	 		 
                             else     $attach_tag.="<table><tr><td style=font-size:12px;><img src='../img/ext/$ext_ico[0]'> <a href='mypims.php?mode=file_download&num=$file_key&pims_no=$pims_no'>$attach_Array[2]</a></td></tr></table>";	 		 

						  }
					 

		 }

}

							

return $attach_tag;




################### end of attach_file_mng #######################
}
################### end of attach_file_mng #######################



################### start of mypims_prg #######################
 function  mypims_prg($connect) { 
################### start of mypims_prg #######################

require "../env/e.fnc";


#시작: 변수정의
	$mypims=Get_Post_Get_Value('mode');
# 끝: 변수정의



#if($mypims_memo['cal_str'])  $rtime=calender_str(2,0,$mypims_memo['cal_str']);
#if($mypims_memo['cal_str']) $query_prg="update mypims_memo set rtime='$rtime[mktime]' where no=".$mypims_memo['no'];
#else 
$query_prg="update mypims_cycle set end_time=$mypims[end_time] where pims_no=".$mypims['pims_no'];

#echo $query_prg;

#exit;


 $result=mysqli_query($connect,"$query_prg");                      

if($result) {
			 Header("Location: mypims.php?mode=read&pims_no=".$mypims['pims_no']);
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);

#echo $query;
#exit;



################### end    of mypims_prg #######################
} 
################### end   of mypims_prg #######################




################### start of update #######################
 function  mypims_delete($connect) { 
################### start of update #######################

require "../env/e.fnc";




#시작: 변수정의
	$mypims=Get_Post_Get_Value('mode');
# 끝: 변수정의


 $query_del="delete from mypims_cycle where pims_no=".$mypims['pims_no'];

 $result=mysqli_query($connect,"$query_del");                      


$query_del_memo="delete from mypims_memo where pims_no=".$mypims['pims_no'];
$result_del_memo=mysqli_query($connect,"$query_del_memo");


if($result) {
			 Header("Location: ../index.php");
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);


################### end   of delete #######################
} ################### end   of delete #######################
################### end   of delete #######################




################### start of Memo_update #######################
 function  mypims_Memo_update($connect) { 
################### start of Memo_update #######################

require "../env/e.fnc";


#시작: 변수정의
	$up_qry="";
	$mypims_memo=Get_Post_Get_Value('mode');
#	$rtime=time();
# 끝: 변수정의




$rtime_array=calender_str(2,0,$mypims_memo['rtime']);

$rtime=$rtime_array['mktime'];




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



################### end   of update #######################
} ################### end   of update #######################
################### end   of update #######################


################### start of memo_prg #######################
 function  mypims_Memo_prg($connect) { 
################### start of memo_prg #######################

require "../env/e.fnc";


#시작: 변수정의
	$mypims_memo=Get_Post_Get_Value('mode');
# 끝: 변수정의

if($mypims_memo['cal_str']) {  $rtime=calender_str(2,0,$mypims_memo['cal_str']);
                               $end_time=$rtime['mktime'];
                            }

if($mypims_memo['end_time_prg']==0) $query_prg="update mypims_memo set rtime='$rtime[mktime]' where no=".$mypims_memo['no'];

else  { 
      
	     if($mypims_memo['end_time_prg']==2) { $end_time=0; }

	    $query_prg="update mypims_memo set end_time=$end_time where no=".$mypims_memo['no'];
	  }

 $result=mysqli_query($connect,"$query_prg");                      

if($result) {
			 Header("Location: mypims.php?mode=read&pims_no=".$mypims_memo['pims_no']);
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);

#echo $query;
#exit;


################### end    of memo_delete #######################
} ################### end   of memo_delete #######################
################### end   of memo_delete #######################



################### start of memo_delete #######################
 function  mypims_Memo_delete($connect) { 
################### start of memo_delete #######################

require "../env/e.fnc";




#시작: 변수정의
	$mypims_memo=Get_Post_Get_Value('mode');
# 끝: 변수정의


 $query_del="delete from mypims_memo where no=".$mypims_memo['no'];

 $result=mysqli_query($connect,"$query_del");                      

if($result) {
			 Header("Location: mypims.php?mode=read&pims_no=".$mypims_memo['pims_no']);
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);

#echo $query;
#exit;



################### end    of memo_delete #######################
} ################### end   of memo_delete #######################
################### end   of memo_delete #######################





############################################
function mypims_Prj_write($connect) {
###########################################
require "../env/e.fnc";

  #변수할당
  $prj=Get_Post_Get_Value('mode');

  # 변수 할당
    
  # 번호가 있다면.. 게시물 내용을 불러올 것
#  $checked_tag=array("","","","","","");

#  $chked_str=array(0,"checked","checked","checked","checked","checked");

  if(is_array($prj) && $prj['prj_no'] ) {

      $query="select * from mypims_prj where prj_no=".$prj['prj_no'];
      $result=mysqli_query($connect,"$query");     

      if($result) $prj = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";



   	   $rtime=calender_str(1,0,$prj['rtime']);
	  }

else { 
	    		
        $today=time();

 	    $mm=date("m",$today);
        $dd=date("d",$today);
		
    	$rtime=calender_str(1,0,$today);	

      }

  
$rtime_str=$rtime['unix_str'];


  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>PRJ 프로젝트 입력</title>
    
			 $style_css

			 $calender_js

	<script language=\"javascript\">
     
			 
			 function chkfrm(f) {	         
				
  if (f.title.value == '')
                      {
		        alert('제목을 입력하세요!');
		        f.title.focus();
		        return;
	              }
	
  else if (f.prj_pwd.value == '')
                      {
		        alert('암호를 입력하세요!');
		        f.prj_pwd.focus();
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

#

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img >

        <table width=900 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td> 
        ";

echo ("
		<form method=post action=\"mypims.php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"prj_update\">
		<input type=\"hidden\" name=\"prj_no\" value=\"".$prj['prj_no']."\">
			
		<table width=\"800\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>


   <tr align=\"LEFT\">
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_pen02.gif'> </font>제 목
      </td>
      <td >	            
   		  <input type=text size='55' name='prj_tit' id='title' class=form_nc value=\"$prj[prj_tit]\">

     <td>
           	 <img src='../img/ic/6.gif' title='시작일'>
			 <input type='text' name='rtime'  id='rtime' value='$rtime_str' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime','','1')\" style='cursor:hand'>				 
    </td>

	<td>
 <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:40px;'>                    
				 <img src='../img/cafe_unlock.gif' title='* 비밀번호를 입력하지 않으면 글을 수정하거나 삭제하실 수 없습니다.'>
				 <input type=\"Password\" name=\"prj_pwd\" value=\"$prj[prj_pwd]\" size=\"10\" maxlength='8' class=form_nc id='prj_pwd'>   
	</td>
     </tr>
 

");



	echo ("

   <tr>
		<td colspan=4>
  ");


# 시작 :스마트 에디터 불러오기
echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
echo"<textarea name=prj_cts id=\"ir1\" style=\"width:766px; height:212px; display:none;\">$prj[prj_cts]</textarea>";

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

echo "</td></tr>";

## 이미지를 불러오고 삽입하는 함수



 # 기본 이미지 사이즈는 insert_attach.php 에서 조정이 가능

echo "
      <script language=\"javascript\">


         function DownLoad_AttAch(f_name) {
												var F_N = f_name;
												ret = showModalDialog('insert_attach.php?mode=dn_dir_file&f_name='+ F_N, window , \"resizable: no; help: no; status: no; scroll: no; \");
											}

       // 업데이트 디렉토리와 이름구분자를 변수로 받아서 insert_attach.php 파일에 넘겨줌.
       function InSeRt_AttAch(grp_no,up_dir) {
		                                        var Grp_No = grp_no;
												var Up_Dir = up_dir;
												ret = showModalDialog('insert_attach.php?mode=get_dir_file&grp_no='+ Grp_No +'&dir_st='+Up_Dir, window , \"resizable: no; help: no; status: no; scroll: no; \");
											}

				count=0;

   function attach_file_fnc(Display_File_Name,File_Str_Array) {

	        			
                   
 					var Up_File_Array = Array();
					Up_File_Array = File_Str_Array.split('&%&');  // [0]이미지파일여부 [1] 확장자 [2] 표시이름 [3] 실제이름 [4] 서버 경로 [5] 가로 [6] 세로

				 if(Up_File_Array[0]==1) {
						      
							Attach_List_Tag='pasteHTM(\' <img src='+ Up_File_Array[4] +' width= '+ Up_File_Array[5] +'  height= '+ Up_File_Array[6] +'>  \');'; 			
						
							
										}
				  
				  else {

                        Attach_List_Tag='pasteHTM(\' <a onclick=DownLoad_Attach(' + Up_File_Array[4] + ')> 다운로드:' + Display_File_Name + '</a> \');'; 			


				  }

					count++;

					var newSpanItem = document.createElement(\"span\"); 
					newSpanItem.setAttribute(\"id\",count); 

					var msg_str = '<td style=font-size:10px;><input type=\"button\" onclick=\"'+ Attach_List_Tag +'\" value=\''+ Display_File_Name +'\' class=form_nc><input type=hidden name=upfile[] value=\''+File_Str_Array+'\'></td>';
											
					newSpanItem.innerHTML = msg_str; 	   

					var itemListNode = document.getElementById('itemList'); 
					itemListNode.appendChild(newSpanItem); 
		}


           // 클릭시 이미지를 본문에 삽입, image 태그를 삽입. 가로,세로 사이즈 알아내서  

                function pasteHTM(input_str) {

					// alert(input_str);
					oEditors.getById[\"ir1\"].exec(\"PASTE_HTML\", [input_str]);
					
				}

			   
	  </script>
";

  $up_Base_Dir="../pims/dta/prj"; 

  #  다운로드는 파일명.. 디렉토리 번호를 넘겨준후에.. 모달창에서 다운로드 받도록 함. 다운로드 할수 있도록 함.

 ## 이미지를 불러오고 삽입하는 함수



echo "  

			<tr align=left height=40>
				<td colspan=4 style='font-size:12px;'><a onclick=\"InSeRt_AttAch('".$prj['prj_no']."','".$up_Base_Dir."/.".$prj['prj_no']."');\" style='cursor:hand'><img src='../img/sweety/8-em-heart.png'>첨부</a>:: &nbsp;<span id='itemList'>  </span>
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
      </tr>

	  	
      </table>  <!-- start of table 000 -->
      ";


echo "</td><tr>

</table>";


 ################### end of write_form #######################
}
################### end of write_form #######################


############################################
function mypims_Prj_Read($connect) {
###########################################
require "../env/e.fnc";

  #변수할당
  $prj=Get_Post_Get_Value('mode');


  $category=get_category("mypims",$opt,$connect);
  # 변수 할당
  



  # 번호가 있다면.. 게시물 내용을 불러올 것
 
      $query="select * from mypims_prj where prj_no=".$prj['prj_no'];
      $result=mysqli_query($connect,"$query");     

      if($result) $prj = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";

$rtime=calender_str(1,0,$prj['rtime']);	 
$rtime_str=$rtime['unix_str'];


 # 게시판 타입
 #  board_type=0 ;; 일반업무 1: 반복업무
 #  
 # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>mypims</title>    
			 $style_css
			 $calender_js
	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=900 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td> 
        ";


# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("					
		<table width=\"800\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>


   <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:10px;'>
         <img src='../img/ic/bul59.gif'> </font>제 목      </td>
      <td colspan=5 width=720>    ". $prj['prj_tit']."     </tr>

 ");

   

echo"<tr><td colspan=4 style='padding-top:1px;padding-left:15px;padding-right:8px;' valign=top><table class=form_nc2 width=100%><Tr height=200><td valign=top>".$prj['prj_cts']."</td></tr></table></td></tr>";


echo "   	 				

   <tr align=\"CENTER\" valign=\"MIDDLE\">
   <td colspan=4 style='font-size:12px;'>  
   | <a href='../index.php'>닫기</a> | &nbsp; &nbsp; | <a href='mypims.php?mode=prj_write&prj_no=".$prj['prj_no']."'>수정</a> | &nbsp; &nbsp; | <a href='#' onclick=\"myconfirm('mypims.php?mode=prj_delete&prj_no=".$prj['prj_no']."','삭제 하시겠습니까?'); return false;\" >삭제</a> |
    </form>

	</td>
	</tr>
 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 


# 시작: 메모 쓰기

echo "   <tr style='font-size:9pt;' height=20><tr><td colspan=4> ";


echo "
				<table style='font-size:10pt;' width=800>
												  <form method=post action=\"mypims.php\" enctype='multipart/form-data' name=myform>	
												  <input type=\"hidden\" name=\"mode\" value=\"prj_Get_update\">
												  <input type=\"hidden\" name=\"prj_no\" value=\"".$prj['prj_no']."\">


												  <tr><td><img src='../img/ic/ic_pen02.gif'><a href='mypims.php?mode=write&prj_no=".$prj['prj_no']."'>새 업무 쓰기</a> &nbsp; &nbsp; <img src='../img/ic/ic_plus.gif'> 가져오기 <input type=text size='4' name='pims_no' id='pims_no' class=form_nc value=\"\">
												  <input type=submit value='등 록'  class=form_nc style='width:45px;'></td></tr>




												 </table>";

echo "</td></tr>";
# 끝: 메모 쓰기




# 시작:메모가 1개 이상 있다면..

  $query_pims="select * from mypims_cycle where prj_no=".$prj['prj_no'];
  $result_pims=mysqli_query($connect,"$query_pims");               


 echo $query_pism;
#  $memo_prg=array("12-em-check.png","완료");


 if(mysqli_num_rows($result_pims)>0) { # start of if num_rows


echo "   <tr style='font-size:9pt;' height=20><tr><td colspan=4>
         <table width=100% style='font-family:굴림,Seoul,arial,helvetica; color:#417295; font-size:12px; border:2px solid; padding-left : 2px;  border:0px dashed;border-left-color:white; border-right-color:white;'><tr><td height=1 colspan=4 align=right style='border:1px dashed;border-top-color:orange;border-bottom-color:white;border-left-color:white; border-right-color:white;'></tr> ";
	
	while($mypims_pims = mysqli_fetch_array($result_pims, MYSQLI_ASSOC)){

			$pims_day=date_str(1,0,$mypims_pims['rtime']);	

			
             $query_memo="select * from mypims_memo where pims_no=".$mypims_pims['pims_no'];
             $result_memo=mysqli_query($connect,"$query_memo"); 
             $memo_num=mysqli_num_rows($result_memo);


#			if($mypims_memo['prg']==0) { $new_prg=1; $prg_strike_tag="";  }
#			else { $new_prg=0; $prg_strike_tag="<strike style='color:gray'>"; }
		#onclick=\"check_mouse('myform.rtime','','1')\" style='cursor:hand'
#          $url="mypims.php?mode=memo_prg&no=".$mypims_memo['no']."&pims_no=".$mypims_memo['pims_no']."&cal_str=";
#<a href='#' >

		echo "<tr height=40><td width=75><img src='../img/ic/16-clock.png'>".$pims_day['str']."<br>
		
		</td>
		<td><a href='mypims.php?mode=read&pims_no=".$mypims_pims['pims_no']."'>".$category['cat_img'][$mypims_pims['cat']]." ".$mypims_pims['title']."<a href='mypims.php?mode=read&pims_no=".$mypims_pims['pims_no']."'> (<img src='../img/ic/29.gif' title='메모갯수'> $memo_num)</a> <img src='../img/ic/12-em-cross.png' onclick=\"myconfirm('mypims.php?mode=prj_Get_update&prj_no=".$mypims_pims['prj_no']."&pims_no=".$mypims_pims['pims_no']."&prj_out=1','프로젝트에서 제외하시겠습니까?'); return false;\" ></a>
		</td></tr>
		";

		echo "	  <tr align=\"LEFT\" valign=\"MIDDLE\"> 
		  					    <td height=1 colspan=4 align=right style='border:1px dashed;border-top-color:orange;border-bottom-color:white;border-left-color:white; border-right-color:white;'></td>
		</tr>
		";

  	}


    
    

echo "</table></td></tr>";

  }  # end of if num_rows
# 끝: 메모가 1개 이상 있다면..





echo "	  	
      </table>  <!-- start of table 000 -->
	  


      ";





echo "</td><tr>

</table>";

echo "</body></html>";

 ################### end of prj_Read_fnc #######################
}
################### end of prj_Read_fcc #######################


################### start of update #######################
 function  mypims_Prj_update($connect) { 
################### start of update #######################

require "../env/e.fnc";


#시작: 변수정의
	$up_qry="";
	$mypims_prj=Get_Post_Get_Value('mode');
#	$rtime=time();
# 끝: 변수정의


$rtime_array=calender_str(2,0,$mypims_prj['rtime']);

$rtime=$rtime_array['mktime'];




if(!$mypims_prj['prj_no']) {
				$query="select max(prj_no) as prj_no from mypims_prj";
				$result=mysqli_query($connect,"$query");


				if($result) { $max_num = mysqli_fetch_array($result, MYSQLI_ASSOC);
				              $mypims_prj['prj_no']=$max_num['prj_no']+1;
				            }

				  else $mypims_prj['prj_no']=1;

						$no_ins=1; # 번호가 없으면 값을 0으로 줘서, 데이터 삽입으로 이동

				} else { $no_ins=0; }


# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($mypims_prj as $pims_key => $pims_value) { # start of cust_val		 

  if($pims_key == 'rtime') continue;
 
  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="rtime='$rtime'";
# 끝: 받은 자료를 가지고 쿼리로 만듬


if($no_ins)   $query="insert into mypims.mypims_prj set $up_qry"; 
else  $query="update mypims_prj set  $up_qry where prj_no=".$mypims_prj['prj_no']; 

#echo $query;

$result=mysqli_query($connect,"$query");                      



if($result) {
			 Header("Location: mypims.php?mode=prj_read&prj_no=".$mypims_prj['prj_no']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

       mysql_close($connect);

#echo $query;
#exit;



################### end   of update #######################
} ################### end   of update #######################
################### end   of update #######################



################### start of memo_prg #######################
 function  mypims_Prj_Get_Update($connect) { 
################### start of memo_prg #######################

require "../env/e.fnc";


#시작: 변수정의
	$prj=Get_Post_Get_Value('mode');
# 끝: 변수정의


   if($prj['prj_out']) $prj_num=0;
   else $prj_num=$prj['prj_no'];




	$query_prj="update mypims_cycle set prj_no=".$prj_num." where pims_no=".$prj['pims_no'];
	$query_memo="update mypims_memo set prj_no=".$prj_num." where pims_no=".$prj['pims_no'];


    $result=mysqli_query($connect,"$query_prj");                      
    $result_memo=mysqli_query($connect,"$query_memo");                      

if($result) {
			 Header("Location: mypims.php?mode=prj_read&prj_no=".$prj['prj_no']);
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);

#echo $query;
#exit;



################### end    of memo_delete #######################
} ################### end   of memo_delete #######################
################### end   of memo_delete #######################



################### start of update #######################
 function  mypims_Prj_delete($connect) { 
################### start of update #######################

require "../env/e.fnc";


#시작: 변수정의
	$prj=Get_Post_Get_Value('mode');
# 끝: 변수정의


    $query="select * from mypims_prj where prj_no=".$prj['prj_no'];
    $result=mysqli_query($connect,"$query");     
    $delete_key = mysqli_fetch_array($result, MYSQLI_ASSOC);


if(!$prj[prj_pwd] or ( $prj['prj_pwd']<>$delete_key['prj_pwd']) ) {

		echo "실수 방지를 위해 프로젝트 삭제를 막아놨습니다. 정말 삭제를 원하시면 비밀 번호를 입력해주세요";

  echo" 

		  <form method=post action=\"mypims.php\" enctype='multipart/form-data' name=myform>	
												  <input type=\"hidden\" name=\"mode\" value=\"prj_delete\">
												  <input type=\"hidden\" name=\"prj_no\" value=\"".$prj['prj_no']."\">

												  <tr><td><input type=text size='4' name='prj_pwd' id='pims_no' class=form_nc value=\"\">
												  <input type=submit value='등 록'  class=form_nc style='width:45px;'></td></tr>";
   exit;

}



 $query_prj="delete from mypims_prj where prj_no=".$prj['prj_no'];
 $result_prj=mysqli_query($connect,"$query_prj");        


 $query_del="delete from mypims_cycle where prj_no=".$prj['prj_no'];
 $result=mysqli_query($connect,"$query_del");                      

 $query_del_memo="delete from mypims_memo where prj_no=".$prj['prj_no'];
 $result_del_memo=mysqli_query($connect,"$query_del_memo");


if($result) {
			 Header("Location: ../index.php");
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);


################### end   of delete #######################
} ################### end   of delete #######################
################### end   of delete #######################


############################################
function mypims_Id_list($connect) {   ############### 인명록 리스트
###########################################


global $admin_info;
global $cur_php;
require "../env/inf.fnc";
require "../env/e.fnc";

  #변수할당

$GR_Vals=Get_Request_Post('mode');

  

$tbl_width=array('t'=>"900",'m'=>"700",'s'=>"200",);

 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>인명록 불러오기</title>    
			 $style_css

	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=".$tbl_width['t']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td valign=top width=".$tbl_width['m']."> 
        ";


echo "					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";


echo "
   <tr align=\"LEFT\" valign=\"MIDDLE\"  style='padding-top:30px;padding-bottom:30px;font-size:17px;background-color:yellow;' >
      <Td style='padding-top:15px;padding-bottom:15px;'><a href='mypims.php?mode=id_write&pims_no=$pims_no'><img src='../img/ic/ic_pen02.gif'style='cursor:hand' title='신규 등록'></a></td>
  
      <td><img src='../img/ic/ic_meet.gif'></td>
      <td><img src='../img/ic/ic_meet.gif'>직장</td>
	  <td>이름</td>
	  <tD>휴대폰</td>
	  <td><a href='$cur_php?mode=id_list'>그룹</td>
     </tr>	  

 ";

    if($GR_Vals['gr_no']) { 

      $query=		"SELECT * FROM `mypims_id_gr` as mig left join mypims_id as mi on mig.id_no=mi.id_no where mig.gr_no='".$GR_Vals['gr_no']."' order by mi.id_name";

	} else  $query="SELECT mi.id_corp_name as id_corp_name, mi.id_no as id_no, mi.id_name as id_name, mi.id_title as id_title, mi.id_phone as id_phone, count(mig.id_no) as tot FROM `mypims_id` as mi left join mypims_id_gr as mig on mi.id_no=mig.id_no group by mi.id_no order by mi.id_name";

# 번호가 있다면.. 게시물 내용을 불러올 것
     
	  
      $result=mysqli_query($connect,"$query");     

# 전체
	  $all_gr_name=all_gr_name_info($connect);

	  
while($idcard = mysqli_fetch_array($result, MYSQLI_ASSOC)){



   # 인명으록 그룹이 잇는지 체크할것. 
$gr_name_tags="";
 $query_gr_id['qry']="select gr_no from mypims_id_gr where id_no='".$idcard['id_no']."' ";
 $result_gr_id=php_mysql_Query($query_gr_id,$connect); 	
if($result_gr_id['value']) 	{
	foreach ($result_gr_id['value'] as $gr_id_key => $gr_id_value)  $gr_name_tags.= "<a href='$cur_php?mode=id_list&gr_no=".$gr_id_value['gr_no']."'>".$all_gr_name[$gr_id_value['gr_no']]['gr_name']."<br>";   
 $gr_name_tags=substr($gr_name_tags,0,-4);
}
	 


echo "<tr align=\"LEFT\" valign=\"MIDDLE\" >
                  <td align=center><a href='mypims.php?mode=pims_no_id_update&pims_no=$pims_no&id_no=".$idcard['id_no']."&no=".$pims_no_id['no']."'>$chk_img</a></td>

				 	  <td align=left style='padding-top:10px;padding-bottom:10px;font-size:12px;'>

					  ".$idcard['tot']."

					 </td>

				  <td align=left style='padding-top:15px;padding-bottom:10px;font-size:12px;'>
					[".$idcard['id_corp_name']."] </td>
				  <td align=left style='padding-top:15px;padding-bottom:10px;font-size:12px;'>
			      <a href='mypims.php?mode=id_view&id_no=".$idcard['id_no']."'>".$idcard['id_name']."</a> (".$idcard['id_title'].")</td>
				  <td>".$idcard['id_phone']."<img src='../img/ic/8-em-cross.png' onclick=\"myconfirm('mypims.php?mode=id_delete&id_no=".$idcard['id_no']."','삭제 하시겠습니까?'); return false;\" style='cursor:hand' title='인명록 삭제'></td>
				  <td>".$gr_name_tags."</td>
			     </tr>				 
				 ";

echo "<tr>".$dot_line."</tr>";

	}

echo "	  	
      </table>  <!-- start of table 000 -->
      ";

echo "</td>

		<td valign=top width=".$tbl_width['s']."> ";

# 번호가 있다면.. 게시물 내용을 불러올 것
 
      $query="SELECT mg.gr_name,mg.gr_no as gr_no, count(mig.gr_no) as tot FROM `mypims_gr` as mg left join mypims_id_gr as mig on mg.gr_no=mig.gr_no GROUP  by mg.gr_name order by gr_name, tot desc";
      $result_id_gr=mysqli_query($connect,$query);     

echo "<table width=\"100%\" border=\"0\" cellspacing=\"7\" cellpadding=\"5\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";

echo   "<tr class=tt4><td width=10><a href='mypims.php?mode=id_grp_write'><img src='../img/ic/ic_pen02.gif'style='cursor:hand' title='신규 등록'></a></td><td><a href='$cur_php?mode=id_list'>그룹명(전체)</td></tr>";

while($id_gr = mysqli_fetch_array($result_id_gr, MYSQLI_ASSOC)){

      
	  if($GR_Vals['gr_no']==$id_gr['gr_no']) { $sel_img="<img src='../img/micon2.gif'>"; $sel_tags="<span style='color:red;font-weight:bold;'>"; }
	  else {

		  $sel_img="";
		  $sel_tags="<a href='$cur_php?mode=id_list&gr_no=".$id_gr['gr_no']."'>";

	  	  }

     	 				
     echo "<tr><td>$sel_img</td><td align=left>".$sel_tags.$id_gr['gr_name']."</a> (".$id_gr['tot'].")</td></tr>";
  
}

echo "</table>";


echo "
		</td>

<tr>

</table>";

echo "</body></html>";

 ################### end of Id_Read_fnc #######################
}
################### end of Id_Read_fcc #######################




################### start   of ID Write #########################
function mypims_Id_write($connect) {
################### start   of ID Write########################

require "../env/e.fnc";
require "../env/inf.fnc";

  #변수할당

$GR_Vals=Get_Request_Post('mode');

      $query="select * from mypims_id where id_no=".$GR_Vals['id_no'];
      $result=mysqli_query($connect,"$query");     
      if($result) $idcard = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";




  #변수할당


  #  id_no, id_name , id_phone , id_cts
  # 변수 할당

#  print_r($mypims);
#  exit;





  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>mypims</title>
    
			 $style_css


	<script language=\"javascript\">
     
			 
			 function chkfrm(f) {	         
				
  if (f.id_name.value == '')
                      {
		        alert('이름을 입력하세요!');
		        f.id_name.focus();
		        return;
	              }
	
					 

    f.submit();	
	
      }

	 </script>

	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=580 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td align=center> 
        ";

echo ("					
		<table width=\"100%\" border=\"1\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;' >

 
<form method=post action=\"mypims.php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"id_update\">
     	<input type=\"hidden\" name=\"id_no\" value=\"".$idcard['id_no']."\">
  
   <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> 이름
      </td>

      <td colspan=3 style='padding-top:5px;font-size:12px;'>
		<input type=text size='5' name='id_name' id='id_name' class=form_nc value=\"".$idcard['id_name']."\"> &nbsp;
			<img src='../img/ic/key.gif'> 호칭 <input type=text size='5' name='id_title' id='id_title' class=form_nc value=\"".$idcard['id_title']."\">

			&nbsp; <img src='../img/ic/ic_meet.gif'> 이메일
			<input type=text size='35' name='id_email' id='id_email' class=form_nc value=\"".$idcard['id_email']."\">
				 </td>
     </tr>

   <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> 직장명
      </td>
      <td colspan=3 style='padding-top:5px;font-size:12px;'>
	  <input type=text size='8' name='id_corp_name' id='id_corp_name' class=form_nc value=\"".$idcard['id_corp_name']."\">
	
         *주소
     <input type=text size='55' name='id_corp_address' id='id_corp_address' class=form_nc value=\"".$idcard['id_corp_address']."\">
				 </td>
     </tr>

     <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_hp.gif'> </font>휴대폰
      </td>
      <td colspan=3><input type=text size='15' name='id_phone' id='id_phone' class=form_nc value=\"".$idcard['id_phone']."\">

	  &nbsp;
	  <img src='../img/ic/key.gif'> 유선 <input type=text size='15' name='id_corp_phone' id='id_corp_phone' class=form_nc value=\"".$idcard['id_corp_phone']."\">

	  &nbsp;
	  <img src='../img/ic/ic_hp.gif'> Fax
	  <input type=text size='15' name='id_corp_fax' id='id_corp_fax' class=form_nc value=\"".$idcard['id_corp_fax']."\">

	  </td>
     </tr>	  

 ");


echo"<tr><td colspan=4 style='padding-top:1px;padding-left:15px;padding-right:8px;' valign=top>
       <textarea name=id_cts id=\"id_cts\" style=\"width:420px; height:88px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc>".$idcard['id_cts']."</textarea>

	    <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;'>
	   
	  </td></tr>";





	echo ("

   <tr>
		<td colspan=4>
  ");



echo "</td></tr>";

## 이미지를 불러오고 삽입하는 함수



 # 기본 이미지 사이즈는 insert_attach.php 에서 조정이 가능




echo "  			    </form>
 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 


echo "

	  	
      </table>  <!-- start of table 000 -->
      ";


echo "</td><tr>

</table>";


 ################### end of ID write #######################
}
#################### end of ID write #######################


############################################
function mypims_Id_vieW($connect) {
###########################################
require "../env/e.fnc";
require "../env/inf.fnc";
global $cur_php;

  #변수할당

$GR_Vals=Get_Request_Post('mode');

  # 번호가 있다면.. 게시물 내용을 불러올 것
 
      $query="select * from mypims_id where id_no=".$GR_Vals['id_no'];
      $result=mysqli_query($connect,"$query");     

      if($result) $idcard = mysqli_fetch_array($result, MYSQLI_ASSOC); else echo "Error";
 
    # 기존에 등록된 고객인지 확인
      $query_pims_no_id="select * from mypims_pims_no_id where pims_no=$pims_no and id_no=".$idcard['id_no']."";
      $result_pims_no_id=mysqli_query($connect,"$query_pims_no_id");   
	  if($result_pims_no_id) $pims_no_id = mysqli_fetch_array($result_pims_no_id, MYSQLI_ASSOC);

    
	 if($pims_no>0) {
	  if($pims_no_id['pims_no']){ $chk_img="<img src='../img/ic/check_on.gif'>"; }
	  else $chk_img="<img src='../img/ic/check_off.gif'>";
	 }
      	  	

# 인명으록 그룹이 잇는지 체크할것. 

 $query_gr_id['qry']="select gr_no from mypims_id_gr where id_no='".$GR_Vals['id_no']."' ";
 $result_gr_id=php_mysql_Query($query_gr_id,$connect); 
			
if($result_gr_id['value']) 	{
	$all_gr_name=all_gr_name_info($connect);
	foreach ($result_gr_id['value'] as $gr_id_key => $gr_id_value)  		    $gr_name_tags.= "".$all_gr_name[$gr_id_value['gr_no']]['gr_name']." , ";

	 $gr_name_tags=substr($gr_name_tags,0,-2);
}

  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>mypims</title>    
			 $style_css
	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\"  background=$bg_img>

        <table width=500 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td> 
        ";


# 태그 정리
# $value[tags]= eregi_replace(",",", ",$value[tags]);

echo ("					
		<table width=\"100%\" border=\"0\" cellspacing=\"5\" cellpadding=\"1\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>

   

  <tr align=\"LEFT\" valign=\"MIDDLE\" height=40px;>
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> <a href='mypims.php?mode=pims_no_id_update&pims_no=$pims_no&id_no=".$idcard['id_no']."&no=".$pims_no_id['no']."'> 이름
      </td>
      <td colspan=3 style='padding-top:5px;font-size:12px;'> ".$idcard['id_name']." &nbsp;    ( ".$idcard['id_title']." )
         <img src='../img/ic/ic_hp.gif'> ".$idcard['id_phone']."

				 </td>
     </tr>


<tr>".$dot_line."</tr>

  <tr align=\"LEFT\" valign=\"MIDDLE\" height=40px;>
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> 이메일
      </td>
      <td colspan=3 style='padding-top:5px;font-size:12px;'>".$idcard['id_email']."</td>
     </tr>

<tr>".$dot_line."</tr>

  <tr align=\"LEFT\" valign=\"MIDDLE\" height=40px;>
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> 직장명
      </td>
      <td colspan=3 style='padding-top:5px;font-size:12px;'>".$idcard['id_corp_name']."
				 </td>
     </tr>

<tr>".$dot_line."</tr>

  <tr align=\"LEFT\" valign=\"MIDDLE\" height=40px;>
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> <a onclick=\"window.open('".$cur_php."?mode=id_grp_write&id_no=".$idcard['id_no']."','pop_grp','width=600, height=1200');\" style='cursor:hand;'> 소속
      </td>
      <td colspan=3 style='padding-top:5px;font-size:12px;'>
	   <span style='font-size:12px;color:blue;'>".$gr_name_tags."</span>
				 </td>
     </tr>

<tr>".$dot_line."</tr>

  <tr align=\"LEFT\" valign=\"MIDDLE\" height=40px;>
      <td align=left style='padding-top:5px;font-size:12px;'>
         <img src='../img/ic/ic_meet.gif'> 주소
      </td>
      <td colspan=3 style='padding-top:5px;font-size:12px;'>".$idcard['id_corp_address']."
				 </td>
     </tr>

<tr>".$dot_line."</tr>

  <tr align=\"LEFT\" valign=\"MIDDLE\" height=40px;>
      <td align=left style='padding-top:5px;font-size:12px;' width=80px;>
	  <img src='../img/ic/key.gif'> 직장(유선)
	  </td>
	        <td colspan=3 style='padding-top:5px;font-size:12px;'>
	  ".$idcard['id_corp_phone']."
	  </td>
     </tr>	  

<tr>".$dot_line."</tr>
 ");


echo"<tr><td colspan=4 style='padding-top:1px;padding-left:15px;padding-right:8px;' valign=top>
			<table style='border: 1px dashed orange; border-radius: 10px; background-color:#F7F8E0; border-spacing:0px;padding:5px;font-size:13px;'  width=100% align=center border=0>
				<Tr height=50><td valign=top>".nl2br($idcard['id_cts'])."</td></tr>
			</table>
		</td></tr>";

echo "  
   <tr align=\"CENTER\" valign=\"MIDDLE\">
   <td colspan=4 style='font-size:12px;'>  
   <a href=javascript:self.close();opener.location.reload();>창닫기</a> 
   | <a href='mypims.php?mode=id_list'>List</a> | &nbsp; &nbsp; | <a href='mypims.php?mode=id_write&id_no=".$idcard['id_no']."'>수정</a> | &nbsp; &nbsp; | 삭제 |
    </form>

	</td>
	</tr>
 ";

# 업데이트후 확장자에 맞춘, 태그를 부여하고, 이미지인 경우.. 마우스로 드래그해서 본문에 입력하면 되도록 함. 또는 onclick 시에.. 


# 관련글이 1개 이상이라면

#  $query_pims="select * from mypims_cycle where prj_no=".$prj['prj_no'];
#  $result_pims=mysqli_query($connect,"$query_pims");               


#  echo $query_pism;
#  $memo_prg=array("12-em-check.png","완료");
#



echo "	  	
      </table>  <!-- start of table 000 -->
	  


      ";





echo "</td><tr>

</table>";

echo "</body></html>";

 ################### end of Id_Read_fnc #######################
}
################### end of Id_Read_fcc #######################




################### start of update #######################
 function  mypims_Id_update($connect) { 
################### start of update #######################

require "../env/e.fnc";

$test_on=0;
$mypims=Get_Request_Post('mode');

#시작: 변수정의
	$up_qry="";

# 끝: 변수정의

$today= date("Y-m-d");   

if($test_on) print_r($mypims);



#$rtime_array=calender_str(2,0,$mypims['rtime']);
#$rtime=$rtime_array['mktime'];


#첨부파일 관리
#$mypims['attach_file']=attach_file_mng($mypims['upfile'],$mypims['upfile_chk'],$mypims['upfile_chk_hidden'],$mypims['upfile_old_display_name'],$mypims['upfile_new_display_name'],$mypims['pims_no']);
#if($mypims['prj_no']=="") $mypims['prj_no']=0;
#$mypims['contents']= preg_replace("/tempUpFile/", "".$mypims['pims_no']."",$mypims['contents'],-1,$count); # 임시파일명이 발견된다면
#$mypims['contents']=addslashes($mypims['contents']);

 $skip_Array=array('pims_no','id_no');

# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($mypims as $pims_key => $pims_value) { # start of cust_val		 

  if(in_array($pims_key,$skip_Array)) continue;
#  if($pims_value=="") $pims_value=0;

  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="uDate='$today'";
# 끝: 받은 자료를 가지고 쿼리로 만듬


if($mypims['id_no'])  $query="update mypims_id set  $up_qry where id_no=".$mypims['id_no'];  
else  $query="insert into mypims_id set $up_qry"; 

if($test_on) { echo $query; exit; }
else  $result=mysqli_query($connect,"$query");                      

if($result) {

   if(empty($mypims['id_no'])) {

$query="select max(id_no) as max_no from mypims_id";
$result_max=mysqli_query($connect,$query);                      
$max_num = mysqli_fetch_array($result_max, MYSQLI_ASSOC);

$mypims['id_no']=$max_num['max_no'];

   }

			 Header("Location: ../pims/mypims.php?mode=id_view&id_no=".$mypims['id_no']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

       mysql_close($connect);

#echo $query;
#exit;


################### end   of update #######################
} ################### end   of update #######################
################### end   of update #######################




################### start of update #######################
 function  mypims_pims_no_id_update ($connect) { 
################### start of update #######################

require "../env/e.fnc";


#시작: 변수정의
	$up_qry="";
	$mypims=Get_Post_Get_Value('mode');
	$rtime=time();
# 끝: 변수정의

#$rtime_array=calender_str(2,0,$mypims['rtime']);
#$rtime=$rtime_array['mktime'];

if(!$mypims['no']) {
				$query="select max(no) as no from mypims_pims_no_id";
				$result=mysqli_query($connect,"$query");

				if($result) { $max_num = mysqli_fetch_array($result, MYSQLI_ASSOC);
				              $mypims['no']=$max_num['no']+1;
				            }

				       else $mypims['no']=1;

			   
			   $ins_no=1;

			}

# $skip_Array=array('upfile','upfile_chk','upfile_chk_hidden','upfile_old_display_name','upfile_new_display_name','upfile','rtime');

# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($mypims as $pims_key => $pims_value) { # start of cust_val		 

#  if(in_array($pims_key,$skip_Array)) continue;
#  if($pims_value=="") $pims_value=0;

  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="rtime='$rtime'";
# 끝: 받은 자료를 가지고 쿼리로 만듬

if($ins_no) $query="insert into mypims_pims_no_id set $up_qry"; 
else  $query="delete from mypims_pims_no_id where no=".$mypims['no'].""; 


#echo $query;
#exit;

 $result=mysqli_query($connect,"$query");                      

if($result) {
			 Header("Location: ../pims/mypims.php?mode=id_list&pims_no=".$mypims['pims_no']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

       mysql_close($connect);

#echo $query;
#exit;


################### end   of update #######################
} ################### end   of update #######################
################### end   of update #######################


################### start of id_delete #######################
 function  mypims_Id_delete($connect) { 
################### start of id_delete #######################

require "../env/e.fnc";

#시작: 변수정의
	$idcard=Get_Request_Post('mode');

$query_del="delete from mypims_id where id_no=".$idcard['id_no'];
$result=mysqli_query($connect,"$query_del");                      


if($result) {
			 Header("Location: mypims.php?mode=id_list");
	        }

      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

      mysql_close($connect);

#echo $query;
#exit;



################### end    of id_delete #######################
}
################### end   of id_delete #######################


################### start of mypims_File_Download #######################
 function  mypims_File_Download($connect) { 
################### start of mypims_File_Download #######################

require "../env/e.fnc";


## db값을 가지고 와서.. 이미지 경로와 세이브 파일명을 찾아서.. 각각 할당함.

#시작: 변수정의
	$file_info=Get_Post_Get_Value('mode');
# 끝: 변수정의





   if($file_info['memo']==0)     $query="select * from mypims_cycle where pims_no=".$file_info['pims_no'];
   else     $query="select * from mypims_memo where no=".$file_info['pims_no'];


#print_r($file_info);
#echo $query;
#exit;



    $result=mysqli_query($connect,"$query");     
    $mypims = mysqli_fetch_array($result, MYSQLI_ASSOC);

$File_Array=explode("&^^^&",$mypims['attach_file']);

$File_Info_Str=$File_Array[$file_info['num']];
$File_Info_Array=explode("&%&",$File_Info_Str);

#print_r($File_Info_Array);

$Ext_Array = explode(".",$File_Info_Array[3]); 
$Ext_Array_Num=count($Ext_Array);
$Ext_Str= $Ext_Array[$Ext_Array_Num-1];
$Save_File_Name=$File_Info_Array[2].".".$Ext_Str;
$Save_File_Name = iconv("utf-8","euc-kr",$Save_File_Name); 
$File_Path=$File_Info_Array[4].$File_Info_Array[3];


header("Pragma: public");
header("Expires: 0");
header("Content-Type: application/octet-stream");
header("Content-Disposition: attachment; filename=\"$Save_File_Name\"");
header("Content-Transfer-Encoding: binary");
header("Content-Length: $filesize");
  
ob_clean();
flush();
readfile($File_Path);

exit;

  
################### end   of mypims_File_Download #######################
} 
################### end   of mypims_File_Download #######################





############################################
function mypims_Id_grp_writE($connect) {
###########################################
global $cur_php;
require "../env/e.fnc";
require "../env/inf.fnc";


$test_on=1;
$GR_Vals=Get_Request_Post('mode');

if($test_on) print_r($GR_Vals);


####   테마 신규 등록 또는 수정,  신규 테마스토리 입력

  $today = date("Y-m-d");


 $mode_two="insert";



  if($GR_Vals['mode_two']=='insert') { ##  테마를 신규 등록
																		                                                                        
																			  $query_ins="insert into mypims_gr set  gr_name='".$GR_Vals['gr_name']."',uDate='".$today."'   ";
																			  $result_ins=mysqli_query($connect,$query_ins); 		
							  }

else if($GR_Vals['mode_two']=='update')  { ##  테마 이름 수정																		
																		   
                                                                         $query_update="update mypims_gr set  gr_name='".$GR_Vals['gr_name']."'  where gr_no='".$GR_Vals['gr_no']."'";
													                     $result_update=mysqli_query($connect,$query_update); 													
																		 $GR_Vals['gr_no']="";
												  }

else if($GR_Vals['mode_two']=='del')  { ##  삭제
	
                                                                         $query_del="delete from mypims_gr where gr_no='".$GR_Vals['gr_no']."'";
													                     $result_del=mysqli_query($connect,$query_del); 													
																		 $GR_Vals['gr_no']="";
												  }


else if($GR_Vals['mode_two']=='id_gr_update')  { ##  인명과 관련된 그룹 정보 갱신
	
																				$query_del="delete from mypims_id_gr where id_no='".$GR_Vals['id_no']."'";
													                           $result_del=mysqli_query($connect,$query_del); 	


	                                                                     			foreach ($GR_Vals['id_gr_no'] as $key => $id_gr_no) {  # start of foreach 001

																							 $query_update="insert into mypims_id_gr  set gr_no='".$id_gr_no."' , id_no='".$GR_Vals['id_no']."'";
																							$result_update=mysqli_query($connect,$query_update); 		

																					}


																		   
                                                                       #  $query_update="update mypims_gr set  gr_name='".$GR_Vals['gr_name']."'  where gr_no='".$GR_Vals['gr_no']."'";
													                    # $result_update=mysqli_query($connect,$query_update); 													
																		# $GR_Vals['gr_no']="";

																		   echo "<body  onload='javascript:self.close();opener.location.reload();'>";

																		   exit;																    


												  }




## 만약 id_no가 있으면..

if($GR_Vals['id_no']) {

														 $query_gr_id['qry']="select gr_no from mypims_id_gr where id_no='".$GR_Vals['id_no']."' ";
														 $result_gr_id=php_mysql_Query($query_gr_id,$connect); 

														 if($result_gr_id['value']) 		 foreach ($result_gr_id['value'] as $gr_id_key => $gr_id_value)  $chbox_checked[$gr_id_value['gr_no']]="checked";

}





				############  시작 :  검색어로 기존에  있던 테마이름 체크

									$query_gr['qry']="select * from mypims_gr order by gr_name";
									$result_gr=php_mysql_Query($query_gr,$connect); 

												  $gr_tags="<table border=0 class=n1s cellspacing=\"4\" cellpadding=\"4\">
																			  <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>
												  																				<input type='hidden'  name=mode  value='id_grp_write'>			
																																<input type='hidden'  name=id_no  value='".$GR_Vals['id_no']."'>			
																																<input type='hidden'  name=mode_two  value='id_gr_update'>
												  ";
												  $gr_tags.="<tr class=tt4><td>그룹명</td><td>uDate</td><Td><img src='../img/check_on.gif' style='cursor:hand;' onclick='document.myform2.submit();' style='cursor:hand;'></td></tr>";
 
                                             if($result_gr['value']) {

																		   foreach ($result_gr['value'] as $r_key => $r_value){

																			   if($GR_Vals['gr_no']==$r_value['gr_no']) {  # 수정모드

																				      $gr_name=$r_value['gr_name'];
																					  $mode_two="update";

																					  $gr_no_tag="<input type='hidden'  name=gr_no  value='".$GR_Vals['gr_no']."'>			";
																			   
																			   }
																			   
																									#	 $r_value['thema_name']= str_replace("$key_word","<font style='color:red;font-weight:bold;font-size:20px;'>$key_word</font>" ,$r_value['thema_name']); 

																										$gr_tags.="<tr class=tt5><td><a href='$cur_php?mode=id_grp_write&gr_no=".$r_value['gr_no']."'>".$r_value['gr_name']."</td><td><a href='#' onclick=\"myconfirm('$cur_php?mode=id_grp_write&gr_no=".$r_value['gr_no']."&mode_two=del','삭제하시겠습니까?'); return false;\" >".$r_value['uDate']."</td><td><input type=checkbox name='id_gr_no[]' value='".$r_value['gr_no']."' ".$chbox_checked[$r_value['gr_no']]."></td></tr>";

																			   }
											 }

															$gr_tags.="</table>";

				############  끝 :  검색어로 기존에  있던 테마이름 체크						   


#<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=00021&thema_no=".$thema_srch_info['thema_no']."&key_word=".$thema_srch_info['thema_name']."','pop_hidden','width=10, height=10');read_change_color('btn_srch_tsi',".$tsi.");put_insert_value('id_srch_key_word','".$thema_srch_info['thema_name']."','srch_thema_keyword','".$thema_srch_info['thema_name']."');\" style='cursor:hand;'>

 echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>mypims</title>
    
			 $style_css


	<script language=\"javascript\">
     
			 
			 	function     put_insert_value(id_name,key_name,id_key_str,id_key_value){

																		//alert(key_word);   테마이름과 테마번호를 넣어줌
	  																      document.getElementById(id_name).innerHTML = key_name;			
																		  
																		//  alert( id_key_value);

																	     document.getElementById(id_key_str).value = id_key_value;																

																	}



			 function chkfrm(f) {	         
				
  if (f.gr_name.value == '')
                      {
		        alert('이름을 입력하세요!');
		        f.gr_name.focus();
		        return;
	              }
	
					 

    f.submit();	
	
      }

	 </script>

	";





echo "								   <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;'> 
										   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																				<input type='hidden'  name=mode  value='id_grp_write'>			
																				<input type='hidden'  name=id_no  value='".$GR_Vals['id_no']."'>			
																				<input type='hidden'  name=mode_two  value='".$mode_two."'>			
																				$gr_no_tag
																				<tr><td align=right >인명 그룹 																				
																				<input type='text' value='".$gr_name."'  name='gr_name' style='width:180px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;' onBlur=\"checkField(this)\" onFocus=\"clearField(this)\">
																				<input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;'>
																				</td></tr>																					
																			</form></td></tr></table>";


echo $gr_tags;



echo "</body></html>";

exit;
		



 ################### end of  Pax_Thema_writE() #######################
}
################### end of  Pax_Thema_writE()#######################


# 전체 인명그룹 가져오기
################### start    of mysql_Query #######################
 function  all_gr_name_info($connect) { 
################### start    mysql_Query #######################

 $gr_name['qry']="select * from mypims_gr";																											
$gr_name['keys'] ='gr_no';																									
$gr_name_array=php_mysql_Query($gr_name,$connect);

$all_gr_name=$gr_name_array['multi_keys'];

     return  $all_gr_name;
  
################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################




?>