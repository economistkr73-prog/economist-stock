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

if($mode=='es_src_list')              { esguide_src_list ($connect); }
elseif($mode=='es_src_write')              { esguide_src_write ($connect); }
elseif($mode=='es_src_update')              { esguide_src_update ($connect); }
elseif($mode=='es_src_view')              { esguide_src_view ($connect); }

elseif($mode=='es_store_list')              { esguide_store_list ($connect); }
elseif($mode=='es_store_view')              { esguide_store_view ($connect); }
elseif($mode=='es_store_print')              { esguide_store_print ($connect); }


elseif($mode=='eh_list')              { esguide_hobby_list ($connect); }
elseif($mode=='eh_wr')          { esguide_hobby_write ($connect); }
elseif($mode=='eh_up')       { esguide_hobby_update ($connect); }
elseif($mode=='eh_view')           { esguide_hobby_view ($connect); }


elseif($mode=='es_story_up')       { esguide_story_update ($connect); }



elseif($mode=='pop_url') pop_go_to_url($connect); 


else  {  echo "<script language=\"javascript\">
    			alert(\" Version : $ver \");
    			</script>      			";			
								esguide_store_list ($connect);
		}

mysqli_close($connect);

############################################
function esguide_src_list($connect) {   ############### 출처 리스트
###########################################

global $admin_info;
global $cur_php;
global $gs_type_cookie;

require "../env/inf.fnc";
require "../env/e.fnc";

  #변수할당

$GR_Vals=Get_Request_Post('mode');

			$Vals_tit=array('gs_type'=> $GR_Vals['gs_type'],'disp'=>"src");

$cate_hdr=category_Header($Vals_tit);

			$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];

  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>".$cur_title." 리뷰 리스트</title>    
			 $style_css

	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=980 align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->

		

        <tr>
        <td valign=top> 
        ";


echo "					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";

#헤더
echo"<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>";
echo $cate_hdr['tags'];

echo "</td></tr>";


echo "
   
      <Td>등록</td>
  
      <td><img src='../img/ic/ic_meet.gif'>제목</td>
	  	  <td>키워드</td>
	  <td>등록일 <a href='$cur_php?mode=es_src_write&gs_type=".$GR_Vals['gs_type']."'><img src='../img/ic/ic_pen02.gif'style='cursor:hand' title='신규 등록'></a></td>
     </tr>	  

 ";
      $query=		"SELECT *,mfs.src_no as src_no, count(*) as tot,mfs.uDate as src_uDate FROM `esguide_src` as mfs left join esguide_src_store as mfss on mfs.src_no=mfss.src_no where mfs.gs_type='".$GR_Vals['gs_type']."' group by mfs.src_no  order by tot desc,store_no desc";	  
      $result=mysqli_query($connect,"$query");     
	  
while($fd_src = mysqli_fetch_array($result, MYSQLI_ASSOC)){

	     if($fd_src['store_no']) $tot=$fd_src['tot']; else $tot="-";

echo "<tr align=\"LEFT\" valign=\"MIDDLE\">
                  <td align=center>				 ".$tot."</td>

				  <td align=left style='padding-top:5px;font-size:12px;'>
				    <a href='$cur_php?mode=es_src_view&src_no=".$fd_src['src_no']."'>".$fd_src['src_title']."</td>


					<td>
					".$fd_src['src_tag_area'].$fd_src['src_tag_menu']."
					
					</tD>
					
				  <td align=left style='padding-top:5px;font-size:12px;'>
				  ".$fd_src['src_uDate']."

				 
			      </td>

			     </tr>				 
				 ";

	}

echo "	  	
      </table>  <!-- start of table 000 -->
      ";

echo "
		</td>
</tr>

</table>";

echo "</body></html>";

 ################### end of Id_Read_fnc #######################
}
################### end of Id_Read_fcc #######################





################### start   of ID Write #########################
function esguide_src_write($connect) {  ## 맛집 소스 입력
################### start   of ID Write########################
global $admin_info;
global $cur_php;
global $mobile;
require "../env/e.fnc";
require "../env/inf.fnc";


if($admin_info['usr_level']!=1) { echo "error"; exit; }

$GR_Vals=Get_Request_Post('mode');



if($mobile) {
					$font_size=array('r'=>"30px;",'h'=>"50px;"); 
                    $tbl_wth=array('m'=>"100%",'s'=>"30");
					$mobile_submit="&nbsp; <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;height:".$font_size['h'].";font-size:".$font_size['r']."'>";

					}
else  { $font_size=array('r'=>"12px;",'h'=>"25px;"); 
$tbl_wth=array('m'=>"580px",'s'=>"60"); 
}


      $query="select * from esguide_src where src_no=".$GR_Vals['src_no'];
      $result=mysqli_query($connect,"$query");     
      if($result) { $fd_src = mysqli_fetch_array($result, MYSQLI_ASSOC);
	                     $GR_Vals['gs_type']=$fd_src['gs_type'];
						}
	  


  #변수할당


			$Vals_tit=array('gs_type'=> $GR_Vals['gs_type'],'disp'=>"src");

$cate_hdr=category_Header($Vals_tit);

			$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];

if($GR_Vals['gs_type']) $gs_type_tag="<input type=\"hidden\" name=\"gs_type\" value=\"".$GR_Vals['gs_type']."\">".$cate_hdr['str'][$GR_Vals['gs_type']];
else 			for($gt=1;$gt<=3;$gt++)      $gs_type_tag.="<input type=\"radio\" name=\"gs_type\" id=\"gs_type_".$gt."\" value=\"".$gt."\" ".$chk_array[$gt]."><a onclick=\"click_checked('gs_type_".$gt."');\" style='cursor:hand;'>".$cate_hdr['str'][$gt];




  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>".$cur_title." 리뷰</title>
    
			 $style_css


	<script language=\"javascript\">
     
			 			 			 
			 function click_checked(click_id) {

     			  var obj = document.getElementById(click_id);

				   obj.checked=true;
             
			}



			 function chkfrm(f) {	         
				
  if ( f.src_url.value=='')
     
                      {
		        alert('이름을 입력하세요!');
		        f.fd_name.focus();
		        return;
	              }

    f.submit();	
	
      }

	 </script>

	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=".$tbl_wth['m']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->
        <tr>
        <td align=center> 
        ";

echo ("					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:".$font_size['r']."' >
 
<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>
".$cate_hdr['tags']."

</td></tr>

<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"es_src_update\">
     	<input type=\"hidden\" name=\"src_no\" value=\"".$fd_src['src_no']."\">
  
       <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:".$font_size['r']."'>
       출처(타이틀)  	
      </td>
      <td colspan=3>
       <input type=text size='".$tbl_wth['s']."' name='src_title' id='src_title'  value=\"".$fd_src['src_title']."\" style='height:".$font_size['h']." padding-bottom:5px;font-size:".$font_size['r']."' class=form_nc>	  
	  </td>
     </tr>	 


       <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;'>
       구분
      </td>
      <td colspan=3 style='font-size:".$font_size['h']."'>
      ".$gs_type_tag."    ".$mobile_submit."
	  </td>
     </tr>	  
 ");


if(!$mobile) {
echo "
     <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=right style='padding-top:5px;' >
       <img src='../img/ico_tag.gif'>
      </td>
      <td colspan=3>
       *지역 &nbsp; <input type=text size='25' name='src_tag_area' id='src_tag_area' class=form_nc value=\"".$fd_src['src_tag_area']."\">
	  &nbsp;
	   *메뉴 &nbsp; <input type=text size='15' name='src_tag_menu' id='src_tag_menu' class=form_nc value=\"".$fd_src['src_tag_menu']."\">
	  </td>
     </tr>";	 
}


echo "
     <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;'>
       출처(URL)
      </td>
      <td colspan=3>
       <input type=text size='".$tbl_wth['s']."' name='src_url' id='src_url' style='height:".$font_size['h']." font-size:".$font_size['r']."' class=form_nc value=\"".$fd_src['src_url']."\">
	  &nbsp;
	  </td>
     </tr>	  

 ";

if(!$mobile) {
echo"<tr><td colspan=4 style='padding-top:1px;padding-left:15px;padding-right:8px;' valign=top>
       <textarea name=src_cts id=\"src_cts\" style=\"width:380px; height:88px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc>".$fd_src['src_cts']."</textarea>

	    <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;'>
	   
	  </td></tr>";
}




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


################### start of Fd_update #######################
 function  esguide_src_update($connect) {  # 맛집 소스 dB업데이트
################### start of Fd_update #######################
global $admin_info;
global $cur_php;
require "../env/e.fnc";

$test_on=0;
$GR_Vals=Get_Request_Post('mode');

#시작: 변수정의
	$up_qry="";

# 끝: 변수정의

$today= date("Y-m-d");   

if($test_on) print_r($GR_Vals);

 $skip_Array=array('src_no');

# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($GR_Vals as $pims_key => $pims_value) { # start of cust_val		 

  if(in_array($pims_key,$skip_Array)) continue;

  if($pims_key=="src_title") $pims_value=addslashes($pims_value);

  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="uDate='$today'";
# 끝: 받은 자료를 가지고 쿼리로 만듬

if($GR_Vals['src_no'])  $query="update esguide_src set  $up_qry where src_no=".$GR_Vals['src_no'];  
else  $query="insert into esguide_src set $up_qry"; 

$Vals=array('tag_area'=>$GR_Vals['src_tag_area'],'tag_menu'=>$GR_Vals['src_tag_menu'],'gs_type'=>$GR_Vals['gs_type']);
mng_fd_tag($Vals,$connect); #태그를 업데이트함

if($test_on) { echo $query; exit; }
else  $result=mysqli_query($connect,"$query");                      

if($result) {

				if(!$GR_Vals['src_no']) {
						$query="select max(src_no) as src_no from esguide_src";
						$result_src=mysqli_query($connect,"$query");
						$get_no = mysqli_fetch_array($result_src, MYSQLI_ASSOC);
						$GR_Vals['src_no']=$get_no['src_no'];
				}

			 Header("Location: ../pims/$cur_php?mode=es_src_view&src_no=".$GR_Vals['src_no']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

#       mysql_close($connect);

#echo $query;
#exit;


################### end   of Fd_update #######################
} ################### end   of Fd_update #######################
################### end   of Fd_update #######################



################### start   of ID Write #########################
function esguide_src_view($connect) {  ## 맛집 소스 보기
################### start   of ID Write########################
global $admin_info;
global $cur_php;
require "../env/e.fnc";
require "../env/inf.fnc";


  #변수할당
$test_on=0;

$GR_Vals=Get_Request_Post('mode');


      $query="select * from esguide_src where src_no=".$GR_Vals['src_no']."";
      $result=mysqli_query($connect,"$query");     
      if($result) { $fd_src = mysqli_fetch_array($result, MYSQLI_ASSOC); 

	        $GR_Vals['gs_type']=$fd_src['gs_type'];

	  }

  #변수할당

			$Vals_tit=array('gs_type'=> $GR_Vals['gs_type'],'disp'=>"src");
            $cate_hdr=category_Header($Vals_tit);
			$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];

  
  #  id_no, id_name , id_phone , id_cts
  # 변수 할당

if($test_on)  print_r($GR_Vals);
#  exit;


$today= date("Y-m-d");   

  if($GR_Vals['mode_two']=='insert') { ##  테마를 신규 등록

						 $Vals=array('tag_menu'=>$GR_Vals['fd_store_tag_menu'],'gs_type'=>$fd_src['gs_type']);
						  mng_fd_tag($Vals,$connect); #태그를 업데이트함

						  $skip_Array=array('mode_two','fd_sotre_no','src_no');
						 $qry= make_qry($GR_Vals,$skip_Array,$opt);

						$query_ins="insert into esguide_store set  $qry,uDate='".$today."'   ";
		   			    $result_ins=mysqli_query($connect,$query_ins); 		

                        # fd_src_store에 등록
						  $qry="select max(store_no) from  esguide_store ";		
						  $result=mysqli_query($connect, $qry); 
						   $get_max_no = mysqli_fetch_array($result);

						   $GR_Vals['store_no']=$get_max_no[0];
						   	$add_store_on=1;
						  }

 else if($GR_Vals['mode_two']=='update') { ##  

						 $Vals=array('tag_menu'=>$GR_Vals['fd_store_tag_menu'],'gs_type'=>$fd_src['gs_type']);
						  mng_fd_tag($Vals,$connect); #태그를 업데이트함

						  $skip_Array=array('mode_two','store_no','src_no');
						 $qry= make_qry($GR_Vals,$skip_Array,$opt);

						$query_ins="update esguide_store set  $qry where store_no='".$GR_Vals['store_no']."'   ";
		   			    $result_ins=mysqli_query($connect,$query_ins); 		
						
						  }



 
if($GR_Vals['mode_two']=='del') { # src 삭제

 $query_del="delete from esguide_src where src_no='".$GR_Vals['src_no']."' ";
 $result=mysqli_query($connect,"$query_del");                      

 $query_del_store="delete from esguide_src_store where src_no='".$GR_Vals['src_no']."' ";
 $result_store=mysqli_query($connect,"$query_del_store");   

     echo "<body onload=location.href='$cur_php?mode=es_src_list&gs_type=".$fd_src['gs_type']."';>       ";

}





else if($GR_Vals['mode_two']=='add_store') { ##  테마를 신규 등록
	$add_store_on=1;
  }

if($add_store_on) {

  $query_ins="insert into esguide_src_store set  src_no='".$GR_Vals['src_no']."',gs_type='".$$fd_src['gs_type']."',store_no='".$GR_Vals['store_no']."',uDate='".$today."'   ";
  $result_ins=mysqli_query($connect,$query_ins); 		

}

  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>[".$cur_title."] ".$fd_src['src_title']."</title>
    
			 $style_css


	<script language=\"javascript\">
     			 
			 function chkfrm(f) {	         
				
  if ( f.fd_store_name.value=='')
     
                      {
		        alert('맛집 이름을 입력하세요!');
		        f.fd_store_name.focus();
		        return;
	              }
				 
    f.submit();	
	
      }


  function open_popup(url,pop_name){ 

						var popupX=	Math.min(event.screenX,window.screen.width / 2)-20;
						var popupY=	Math.min(event.screenY,window.screen.height / 2)-30;					

						if(pop_name=='utube') {

							  wth=1000;
							  hgt=1000;

						}							
																																		 
						 var size ='width='+wth+',height='+hgt+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 

						var n=open(url,name,size); 
						n.focus(); 						
						}

	 function add_fd_store(src_no,store_no) {	         

					go_to_url  ='$cur_php?mode=es_src_view&mode_two=add_store&src_no='+src_no+'&store_no='+store_no+'';         

					  if (confirm(\"등록하시겠습니까?\")) {

						     window.document.location.href=go_to_url;

						}
	
      }




	 </script>

	";


$TOT_Wth="980";
$M_Wth=	"750";
$S_Wth=$ToT_Wth-$M_Wth;

echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' >

        <table width=$TOT_Wth align=\"center\" border=0 cellspacing=\"1\" cellpadding=\"0\" bgcolor=white > <!-- start of table 000 -->

        <tr>
        <td align=center width=$M_Wth valign=top>  
        ";

echo "					
		<table width=\"100%\" border=\"0\" cellspacing=\"10\" cellpadding=\"1\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;' >   <!-- start of table 001 -->";


echo "<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>";

echo $cate_hdr['tags'];

echo "</td></tr>";

echo "
       <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:12px;'>
       <img src='../img/c2.gif'> 출처(타이틀)
      </td>

      <td colspan=3 style='font-size:17px;font-weight:bold;'>
	  <a href='#' onclick= open_popup('".$fd_src['src_url']."','utube') style='color:blue;'>
       ".$fd_src['src_title']." <img src='../img/utube.png'></a> 	  
	  </td>
     </tr>	  

 ";

echo"<tr><td colspan=4 style=' width:380px; height:28px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;' valign=top>
<img src='../img/bul59.gif'> ".nl2br($fd_src['src_cts'])."	   
	  </td></tr>";

if($admin_info['usr_level']==1) { $modi_tag="<a href='$cur_php?mode=es_src_write&src_no=".$GR_Vals['src_no']."'><img src='../img/icn_pen02.gif'>수정하기</a> | <a href='#' onclick=\"myconfirm('$cur_php?mode=es_src_view&mode_two=del&src_no=".$GR_Vals['src_no']."','삭제 하시겠습니까?'); return false;\" ><img src='../img/ic/12-em-cross.png'>삭제</a> |"; }

echo "	 <tr><td colspan=4 align=center>
      ".$modi_tag."	   <a href='$cur_php?mode=es_src_list&gs_type=".$fd_src['gs_type']."'>List</a>
	 </td>
	 </tr>";

					# 시작 : 맛집 리스트
					echo"<tr>
					<td colspan=4>
										<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='border: 1px dashed orange; border-radius: 7px; border-spacing:3px;font-size:14px;' >  <!-- start of table 002 -->
										<tr style='font-size:14px;background-color:yellow;' height=30px;><Td colspan=2>".$cur_title." 이름</td><td>주소</td><td>연락처</td></tr>";

                                          $query="select * from esguide_src_store as mfss left join esguide_store as mfs on mfss.store_no=mfs.store_no where mfss.src_no=".$GR_Vals['src_no']."";
										  $result_store=mysqli_query($connect,$query);  
										  

										if($result_store) while($es_store = mysqli_fetch_array($result_store, MYSQLI_ASSOC)){

											  $exist_store_no[]=$es_store['store_no'];

											  #평점이 있으면..
											  if($es_store['fd_point_naver']>0) $point_tag="</a><br><img src='../img/star_red.gif'> <font style='font-size:17px;font-weight:bold;'>".$es_store['fd_point_naver']."</font><br><img src='../img/ic_bm.gif'> ".number_format($es_store['fd_point_visit'])." <img src='../img/bul59.gif'> ".number_format($es_store['fd_point_blog']).""; else $point_tag="";

											 if($admin_info['usr_level']==1)  $modify_tag="<a href='$cur_php?mode=es_src_view&src_no=".$GR_Vals['src_no']."&store_no=".$es_store['store_no']."'>";
											 else $modify_tag="";

											 $open_pop_tags="<a href='#' onclick= open_popup('".$es_store['fd_store_address_url']."',100,'utube') style='color:blue;'>";


										 echo "	<tr>
										 <td rowspan=2>
										 ".$open_pop_tags."<img src='../img/fd.png'></a></td>
										 <Td width=110px; rowspan=2  style='padding-top:5px;padding-bottom:5px;'>".$modify_tag.$es_store['fd_store_name'].$point_tag."</td>
										 <td width=300px; style='padding-top:5px;padding-bottom:5px;'>".$open_pop_tags.$es_store['fd_store_address']." <img src='../img/naver_map.png'></td>
										 <td>".$es_store['fd_store_phone']."</td></tr>
										 
										 <tr><td colspan= 2 style='font-size:12px;'>".nl2br($es_store['fd_store_cts'])."</td></tr>";

										 echo "<tr>$dot_line</tr>";

						} else echo "등록해주세요";

					  echo "</table>"; # <!-- end of table 002 -->
					  
echo "</td></tr>";  

if($admin_info['usr_level']==1) {
				## 맛집리스트 등록
echo"<tr><td colspan=4>";

 if($GR_Vals['store_no']) {

                                          $query_store_no="select * from esguide_store where  store_no=".$GR_Vals['store_no']."";
										  $result_store_no=mysqli_query($connect,$query_store_no); 
										  $store = mysqli_fetch_array($result_store_no, MYSQLI_ASSOC);
										
										  $update_tag="<input type=\"hidden\" name=\"mode_two\" value=\"update\">";

 } else 							  { $update_tag="<input type=\"hidden\" name=\"mode_two\" value=\"insert\">";
				                         $store['fd_store_tag_menu']=$fd_src['src_tag_menu'];
 }


					echo "
								<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"2\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;background-color:yellow;' >   <!-- start of table 003 -->
									<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
									<input type=\"hidden\" name=\"mode\" value=\"es_src_view\">
									".$update_tag."									
									<input type=\"hidden\" name=\"store_no\" value=\"".$store['store_no']."\">
									<input type=\"hidden\" name=\"src_no\" value=\"".$GR_Vals['src_no']."\">
									<input type=\"hidden\" name=\"gs_type\" value=\"".$fd_src['gs_type']."\">
							  
							   <tr align=\"LEFT\" valign=\"MIDDLE\">
								  <td align=left style='padding-top:5px;font-size:12px;'>
									 <img src='../img/ic/ic_meet.gif'> ".$cur_title." 이름
								  </td>

								  <td colspan=3 style='padding-top:5px;font-size:12px;'>
								  <input type=text size='20' name='fd_store_name' id='fd_store_name' class=form_nc  value=\"".$store['fd_store_name']."\">
								
									 <img src='../img/ic/ic_hp.gif'> 연락처
								 <input type=text size='35' name='fd_store_phone' id='fd_store_phone' class=form_nc value=\"".$store['fd_store_phone']."\">
											 </td>
								 </tr>

								 <tr align=\"LEFT\" valign=\"MIDDLE\">
								  <td align=left style='padding-top:5px;font-size:12px;'>
									 <img src='../img/ic/c2.gif'> </font>주소
								  </td>
								  <td colspan=3> <input type=text size='45' name='fd_store_address' id='fd_store_address' class=form_nc value=\"".$store['fd_store_address']."\">


								  <br>
									(url) <input type=text size='25' name='fd_store_address_url' id='fd_store_address_url' class=form_nc value=\"".$store['fd_store_address_url']."\">
									(특화거리)<input type=text size='15' name='street_name' id='street_name' class=form_nc value=\"".$store['street_name']."\">
								  </td>
								 </tr>	
								 
								  <tr align=\"LEFT\" valign=\"MIDDLE\">
								  <td align=left style='padding-top:5px;font-size:12px;'>
									 <img src='../img/ico_tag.gif'> </font>메뉴
								  </td>
								  <td colspan=3> <input type=text size='45' name='fd_store_tag_menu' id='fd_store_tag_menu' class=form_nc value=\"".$store['fd_store_tag_menu']."\">								  
								  </td>
								 </tr>	

								   <tr align=\"LEFT\" valign=\"MIDDLE\">
								  <td align=left style='padding-top:5px;font-size:12px;'>
									 <img src='../img/ico_tag.gif'> </font>네이버
								  </td>
								  <td colspan=3> 평점<input type=text size='3' name='fd_point_naver' id='fd_point_naver' class=form_nc value=\"".$store['fd_point_naver']."\" onFocus=\"clearField(this)\">								  
								   방문자<input type=text size='4' name='fd_point_visit' id='fd_point_visit' class=form_nc value=\"".$store['fd_point_visit']."\" onFocus=\"clearField(this)\">								  
			   					   블로그 리뷰<input type=text size='4' name='fd_point_blog' id='fd_point_blog' class=form_nc value=\"".$store['fd_point_blog']."\" onFocus=\"clearField(this)\">					
								  </td>
								 </tr>	

								 <tr align=\"LEFT\" valign=\"MIDDLE\">
								  <td align=left style='padding-top:5px;font-size:12px;'>
									 <img src='../img/ic/bul59.gif'> </font>코멘트
									 <Br>
									 <input type=button value='맛집 등록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;'>

								  </td>
								  <td colspan=3> 
								   <textarea name=fd_store_cts id=\"fd_store_cts\" style=\"width:380px; height:38px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" >".$store['fd_store_cts']."</textarea>
								   </form>
								  </td>
								 </tr>	  
							</table>";  # <!-- end of table 003 -->

echo "</td></tr>";	# 끝 :맛집 등록  

}

 echo "</table>";   #<!-- start of table 001 -->
 echo "</td>";

 echo "<td valign=top width=$S_Wth>";

     if($fd_src['src_tag_area']) { $src_tag_array=$fd_src['src_tag_area'];  $srch_key='fd_store_address'; }
	 else { $src_tag_array=$fd_src['src_tag_menu']; $srch_key='fd_store_cts'; }

      echo "<table width=\"100%\" border=\"0\" cellspacing=\"1\" cellpadding=\"1\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;' >   <!-- start of table 001 -->";

	   echo  "<tr><Td><img src='../img/ico_tag.gif'></td><td style='font-size:20px;font-weight:bold;color:blue;' >".$src_tag_array."</tD></tr>";
								


								 $src_tag=explode(',',$src_tag_array);

							       

								 foreach ($src_tag as $src_key => $src_value) { # start of cust_val		 
										 $srch_qry.=$srch_key." LIKE '%".$src_value."%' or ";
								 }
										 $srch_qry=substr($srch_qry,0,-4);

                                                     if($admin_info['usr_level']==1) $order_qry="order by fd_store_name";
													 else $order_qry="order by (fd_point_naver+fd_point_visit/1000+fd_point_blog/500) desc";
													

 if($src_tag[0]) {

															  $query_srch="select * from esguide_store where  ".$srch_qry.$order_qry ;
															  $result_store_srch=mysqli_query($connect,$query_srch);     
															  	
																	 while($es_store_srch = mysqli_fetch_array($result_store_srch, MYSQLI_ASSOC)){

																		$add_store_tags="<img src='../img/cb.gif' style='cursor:hand;' onclick=\"add_fd_store('".$GR_Vals['src_no']."','".$es_store_srch['store_no']."')\">"; 

																	  if($exist_store_no)  if(in_array($es_store_srch['store_no'],$exist_store_no) ) { $add_store_tags="";  } 

																	  if($admin_info['usr_level']!=1) $add_store_tags="";

																	  if($es_store_srch['fd_point_naver']>0) $naver_point_tag="(<font style='font-size:14px;color:red;'>★".$es_store_srch['fd_point_naver'].")"; else $naver_point_tag="";

																	  
																	echo "<tr><td width=10px; align=right valign=top><a href='#' onclick= open_popup('".$es_store_srch['fd_store_address_url']."',100,'n') style='color:blue;'><img src='../img/fd.png'> </td>
																	<td style='padding-top:7x;padding-bottom:7px;'><font style='font-weight:bold;font-size:15px;'>".$es_store_srch['fd_store_name']."</a></font> ".$add_store_tags."<br>".$naver_point_tag."

																	
																	<br> <font style='color:blue;font-size:11px;'>(".$es_store_srch['fd_store_tag_menu'].") </td></tR>";

																}

 }




  echo "</table>";													





 echo "
</td>
</tr>

 ";





echo "</td><tr>

</table>";


 ################### end of ID write #######################
}
#################### end of ID write #######################



############################################
function esguide_store_list($connect) {   ############### 맛집  리스트
###########################################
global $admin_info;
global $cur_php;
require "../env/inf.fnc";
require "../env/e.fnc";

  #변수할당

$GR_Vals=Get_Request_Post('mode');

$tbl_width=array('t'=>"1060",'m'=>"700",'s'=>"360",);

            if(!$GR_Vals['gs_type']) $GR_Vals['gs_type']=1;
            $Vals_tit=array('gs_type'=> $GR_Vals['gs_type'],'disp'=>"store");
			$cate_hdr=category_Header($Vals_tit);

			$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];


if($GR_Vals['mode_two']=='del_tag') {

 $query_del="delete from esguide_tag where gs_type='".$GR_Vals['gs_type']."' and (tag_menu='".$GR_Vals['key_word']."' or tag_area='".$GR_Vals['key_word']."') ";
 $result=mysqli_query($connect,"$query_del");                      


}


  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>".$cur_title."</title>    
			 $style_css




	<script language=\"javascript\">

  function open_popup(url,size,name){ 

						var popupX=	Math.min(event.screenX,window.screen.width / 2)-20;
						var popupY=	Math.min(event.screenY,window.screen.height / 2)-30;					
																																		 
						 var size =size+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 

						var n=open(url,name,size); 
						n.focus(); 						
						}

	 </script>

</head>

	";


echo "


        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=".$tbl_width['t']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"1\" bgcolor=white> <!-- start of table 000 -->";

echo "<tr valign=\"MIDDLE\"><td style='padding-top:5px;padding-bottom:5px;padding-left:25px;font-size:30px;'>";

echo $cate_hdr['tags'];

echo "</td><td></td></tr>";

echo"
        <tr>
        <td valign=top width=".$tbl_width['m']."> 
        ";


echo "					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"5\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";



	# 시작 : 맛집 리스트
					echo"<tr>
					<td colspan=4>
										<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='border: 1px dashed orange; border-radius: 7px; border-spacing:3px;font-size:14px;' >  <!-- start of table 002 -->";

										echo "<tr style='font-size:14px;background-color:black;' height=30px;><Td colspan=4 align=center style='color:yellow;font-size:17px;'>Woong's ".$cur_title." 리스트</font> </td></tr>";

										$srch_qry=" where gs_type='".$GR_Vals['gs_type']."'";
																	
										# 만약 키워드가 있다면..
										if($GR_Vals['srch_key']) { 											    
											   $chk_sep=explode('/',$GR_Vals['srch_word']);
											    $srch_qry.=" and (";									           
											 	foreach($chk_sep as $cs_key=>$cs_title) $srch_qry.= "  ".$GR_Vals['srch_key']." Like '%".$cs_title."%' and ";
												$srch_qry=substr($srch_qry,0,-4);										
										}
									
										if($GR_Vals['srch_key']) $srch_qry.=" or street_name   Like '%".$cs_title."%')";
																	

										  # 페이지 가져오기
										
										  $Vals_pages=array('db_name'=>"esguide_store",'limit_no'=>10,'pages'=>$GR_Vals['pages'],'srch_qry'=>$srch_qry,'srch_word'=>$GR_Vals['srch_word'],'srch_key'=>$GR_Vals['srch_key'],'gs_type'=> $GR_Vals['gs_type']);
										  $pages_tag_array=get_pages($Vals_pages,$connect);

										  if($GR_Vals['srch_key']) $max_tot=$pages_tag_array['tot']; else $max_tot=10;
										
                                          $query="select * from esguide_store ".$srch_qry."  order by (fd_point_naver+fd_point_visit/1000+fd_point_blog/500) desc  limit ".$pages_tag_array['cur_start_no'].", ".$max_tot." ";
										  $result_store=mysqli_query($connect,$query); 

																																				
											echo "<tr style='font-size:14px;background-color:black;' height=30px;><Td colspan=4 align=center>".$pages_tag_array['pages_tag']."</td></tr>";
										echo "								<tr style='font-size:14px;background-color:yellow;' height=30px;><Td colspan=2>".$cur_title." 이름</td><td>주소</td><td>연락처</td></tr>";

										
											if($result_store) while($es_store = mysqli_fetch_array($result_store, MYSQLI_ASSOC)){

												$store_view_tag="<a href='$cur_php?mode=es_store_view&store_no=".$es_store['store_no']."'>";

										
											#$tot_point=$es_store['fd_point_naver']+($es_store['fd_point_visit']/1000)+($es_store['fd_point_blog'])/500;

											 $Vals=array('store_no'=>$es_store['store_no']);
											$get_src_tag=get_src_tag($Vals,$connect);

											if($es_store['fd_point_naver']>0) $point_tag="</a><br><img src='../img/star_red.gif' title='평점'> <font style='font-size:17px;font-weight:bold;'>".$es_store['fd_point_naver']."</font><br><img src='../img/ic_bm.gif' title='방문자'> ".number_format($es_store['fd_point_visit'])." <br>&nbsp;<img src='../img/bul59.gif' title='블로그리뷰'> ".number_format($es_store['fd_point_blog']).""; else $point_tag="";

											$open_map_link="<a href='#' onclick= open_popup('".$es_store['fd_store_address_url']."',100,'n') >";

											$street_name_tags="";
											if($es_store['street_name']) $street_name_tags="<img src='../img/ico_thema.gif'> ".$es_store['street_name'];											

											#리뷰가져오기
											  $Vals=array('root_no'=>$es_store['store_no'],'root_type'=>'es_store');
					                          $get_story_tag= get_story_tag($Vals,$connect);
											  if($get_story_tag['review']) $review_tag="<tr><td>".$store_view_tag.$get_story_tag['review']."</a></tD></tr>";
											  else $review_tag="<tr><td></td></tr>";

										 echo "	<tr>
										 <td rowspan=5>".$open_map_link."<img src='../img/fd.png'></a></td>
										 <Td width=110px; rowspan=5  style='padding-top:5px;padding-bottom:5px;'>".$store_view_tag.$es_store['fd_store_name'].$point_tag."</td>
										 <td width=350px; style='padding-top:5px;padding-bottom:5px;font-size:13px;'>".$open_map_link.$es_store['fd_store_address']." <img src='../img/naver_map.png'></td><td>".$es_store['fd_store_phone']."</td>
										 </tr>
										 <tr><td colspan= 2 style='font-size:12px;'>".nl2br($es_store['fd_store_cts'])."</td></tr>										 
										 <tr><td style='font-size:12px;padding-top:5px;padding-bottom:5px;'><img src='../img/ico_tag.gif'>".nl2br($es_store['fd_store_tag_menu'])." </td><td style='color:red;font-size:11px;'>".$street_name_tags."</td></tr>
										 <Tr><td colspan=2>".$get_src_tag."</td></tr>

										 ";
										 echo $review_tag;
										

										 echo "<tr>$dot_line</tr>";

						} 

					  echo "</table>"; # <!-- end of table 002 -->
					  
echo "</td></tr>";  



echo "	  	
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";


				echo"
						<td valign=top width=".$tbl_width['s']."> ";
				# 번호가 있다면.. 게시물 내용을 불러올 것
				 $Vals_mt=array('srch_word'=>$GR_Vals['srch_word'],'gs_type'=> $GR_Vals['gs_type']);
				$make_tag_list= store_tag_list($Vals_mt,$connect);

				echo "</td>";


echo"
</tr>
</table>";

echo "</body></html>";

 ################### end of Id_Read_fnc #######################
}
################### end of Id_Read_fcc #######################


############################################
function esguide_store_view($connect) {   ############### 맛집  리스트
###########################################
global $admin_info;
global $cur_php;
require "../env/inf.fnc";
require "../env/e.fnc";

  #변수할당

$GR_Vals=Get_Request_Post('mode');

$tbl_width=array('t'=>"1060",'m'=>"700",'s'=>"360",);

            if(!$GR_Vals['gs_type']) $GR_Vals['gs_type']=1;
            $Vals_tit=array('gs_type'=> $GR_Vals['gs_type'],'disp'=>"store");
			$cate_hdr=category_Header($Vals_tit);

			$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];


if($GR_Vals['mode_two']=='del_tag') {

																	 $query_del="delete from esguide_tag where gs_type='".$GR_Vals['gs_type']."' and (tag_menu='".$GR_Vals['key_word']."' or tag_area='".$GR_Vals['key_word']."') ";
																	 $result=mysqli_query($connect,"$query_del");                      

																}


			  $srch_qry=" where store_no='".$GR_Vals['store_no']."'";										
              $query="select * from esguide_store ".$srch_qry."  ";
			  $result_store=mysqli_query($connect,$query); 



$today_ptime=calender_str(1,0,time());
$start_time=$today_ptime['unix_str'];

  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>".$cur_title."</title>    
			 $style_css
$calender_js

	<script language=\"javascript\">


					function      submit_popup(v) {		

																																												 //  폼으로 넘어온 변수 이름과 값을 확인

																																 if(0) {
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}
																																																				
																										   if(v.tit.value=='' || v.tit.value=='내용을 적어주세요') { 
																											                                            alert('내용없음'); 
																										                                                return;
																																						}  // 제목이 없으면 등록 취소




					window.open('', 'pop_title','width=10, height=10') ;
							
					v.target = 'pop_title';
					v.action = '".$cur_php."';
					v.submit() ;

								
																									
																									
																									//		  v.submit();																											
																						
																																																																	
																							}


	 </script>

</head>

	";


echo "

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=".$tbl_width['t']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"1\" bgcolor=white> <!-- start of table 000 -->";

echo "<tr valign=\"MIDDLE\"><td style='padding-top:5px;padding-bottom:5px;padding-left:25px;font-size:30px;'>";

echo $cate_hdr['tags'];

echo "</td><td></td></tr>";

echo"
        <tr>
        <td valign=top width=".$tbl_width['m']."> 
        ";


echo "					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"5\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";



	# 시작 : 맛집 리스트
					echo"<tr>
					<td colspan=4>
										<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='border: 1px dashed orange; border-radius: 7px; border-spacing:3px;font-size:14px;' >  <!-- start of table 002 -->";

										echo "<tr style='font-size:14px;background-color:black;' height=30px;><Td colspan=4 align=center style='color:yellow;font-size:17px;'>Woong's ".$cur_title." 리스트</font> </td></tr>";

											
										echo "								<tr style='font-size:14px;background-color:yellow;' height=30px;><Td colspan=2>".$cur_title." 이름</td><td>주소</td><td>연락처</td></tr>";

										
											$es_store = mysqli_fetch_array($result_store, MYSQLI_ASSOC);
										
										    $Vals=array('store_no'=>$es_store['store_no']);
											$get_src_tag=get_src_tag($Vals,$connect);


											if($es_store['fd_point_naver']>0) $point_tag="</a><br><img src='../img/star_red.gif' title='평점'> <font style='font-size:17px;font-weight:bold;'>".$es_store['fd_point_naver']."</font><br><img src='../img/ic_bm.gif' title='방문자'> ".number_format($es_store['fd_point_visit'])." <br>&nbsp;<img src='../img/bul59.gif' title='블로그리뷰'> ".number_format($es_store['fd_point_blog']).""; else $point_tag="";

											$open_map_link="<a href='#' onclick= open_popup('".$es_store['fd_store_address_url']."',100,'n') >";

											$street_name_tags="";
											if($es_store['street_name']) $street_name_tags="<img src='../img/ico_thema.gif'> ".$es_store['street_name'];
											

										 echo "	<tr>
										 <td rowspan=4>".$open_map_link."<img src='../img/fd.png'></a></td>
										 <Td width=110px; rowspan=4  style='padding-top:5px;padding-bottom:5px;'>".$es_store['fd_store_name'].$point_tag."</td>
										 <td width=350px; style='padding-top:5px;padding-bottom:5px;font-size:13px;'>".$open_map_link.$es_store['fd_store_address']." <img src='../img/naver_map.png'></td><td>".$es_store['fd_store_phone']."</td>
										 </tr>
										 <tr><td colspan= 2 style='font-size:12px;'>".nl2br($es_store['fd_store_cts'])."</td></tr>
										 <tr><td style='font-size:12px;padding-top:5px;padding-bottom:5px;'><img src='../img/ico_tag.gif'>".nl2br($es_store['fd_store_tag_menu'])." </td><td style='color:red;font-size:11px;'>".$street_name_tags."</td></tr>
										 <Tr><td colspan=2>".$get_src_tag."</td></tr>
										 ";
										 
										 echo "<tr>$dot_line</tr>";



                      echo "<tr><td colspan=4>";

					  echo "  <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																													<input type=hidden name=mode  value='es_story_up'>
																																													<input type=hidden name=root_type  value='es_store'>
																																													<input type=hidden name=root_no  value='".$es_store['store_no']."'>
																																													<img src='../img/micon1.gif'>메모&nbsp; <input type='text' name=tit  id='tit' value='내용을 적어주세요' size='38'  class=form_nc $auto_clear_tag>
																																													<input type='text' name='uDate'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform.start_time','','0');\" style='cursor:hand'>
																																													<input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_popup(document.myform)\"></form>";


					  echo "</td></tr>";



				 echo "<tr>$dot_line</tr>";
                     echo "<tr><td colspan=4>";

					  $Vals=array('root_no'=>$es_store['store_no'],'root_type'=>'es_store');

					  $get_story_tag= get_story_tag($Vals,$connect);

					   echo $get_story_tag['tag'];

					  echo "</td></tr>";

					  echo "</table>"; # <!-- end of table 002 -->
					  
echo "</td></tr>";  






echo "	  	
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";


				echo"
						<td valign=top width=".$tbl_width['s']."> ";
				# 번호가 있다면.. 게시물 내용을 불러올 것
				 $Vals_mt=array('srch_word'=>$GR_Vals['srch_word'],'gs_type'=> $GR_Vals['gs_type']);
				$make_tag_list= store_tag_list($Vals_mt,$connect);

				echo "</td>";


echo"
</tr>
</table>";

echo "</body></html>";

 ################### end of Id_Read_fnc #######################
}
################### end of Id_Read_fcc #######################



############################################
function esguide_store_print($connect) {   ############### 맛집  리스트 프린트
###########################################
global $admin_info;
global $cur_php;
require "../env/inf.fnc";
require "../env/e.fnc";


  #변수할당

$GR_Vals=Get_Request_Post('mode');


            $Vals_tit=array('gs_type'=> $GR_Vals['gs_type'],'disp'=>"store");
			$cate_hdr=category_Header($Vals_tit);

			$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];

  $tbl_width=array('t'=>"1200",'m'=>"1200",'s'=>"0",);
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>".$cur_title." 리스트</title>    
			 $style_css

	";


echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=".$tbl_width['t']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"1\"> <!-- start of table 000 -->

        <tr>
        <td valign=top width=".$tbl_width['m']."> 
        ";


echo "					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"5\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";

	# 시작 : 맛집 리스트
					echo"<tr>
					<td colspan=4>
										<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='border: 1px dashed orange; border-radius: 7px; border-spacing:3px;font-size:14px;' >  <!-- start of table 002 -->";

										echo "<tr style='font-size:14px;background-color:yellow;' height=30px;><Td colspan=4 align=center style='font-size:17px;font-weight:bold;'>'".$GR_Vals['srch_word']."' 으로 검색한 ".$cur_title." 리스트입니다.</td></tr>";

										$srch_qry=" where gs_type='".$GR_Vals['gs_type']."'";
										# 만약 키워드가 있다면..
										if($GR_Vals['srch_key']) { 
											   $chk_sep=explode('/',$GR_Vals['srch_word']);
											    $srch_qry.=" and ";
									           
											 	foreach($chk_sep as $cs_key=>$cs_title) $srch_qry.= "  ".$GR_Vals['srch_key']." Like '%".$cs_title."%' and ";
												$srch_qry=substr($srch_qry,0,-4);										
										}

										  # 페이지 가져오기
										
										  $Vals_pages=array('db_name'=>"esguide_store",'limit_no'=>10,'pages'=>$GR_Vals['pages'],'srch_qry'=>$srch_qry,'srch_word'=>$GR_Vals['srch_word'],'srch_key'=>$GR_Vals['srch_key'],'gs_type'=> $GR_Vals['gs_type']);
										  $pages_tag_array=get_pages($Vals_pages,$connect);

										  if($GR_Vals['srch_key']) $max_tot=$pages_tag_array['tot']; else $max_tot=10;			
                                          $query="select * from esguide_store ".$srch_qry." order by (fd_point_naver+fd_point_visit/1000+fd_point_blog/500) desc limit ".$pages_tag_array['cur_start_no'].", ".$max_tot." ";
										  $result_store=mysqli_query($connect,$query);     

										#echo "	<tr style='font-size:14px;' height=30px;>";

											echo "<tr style='background-color:yellow;'><Td>맛집이름</td><td>대표메뉴</td></tr>";


																					if($result_store) while($es_store = mysqli_fetch_array($result_store, MYSQLI_ASSOC)){

																						$k++;

																						$point_tag="</a><br><img src='../img/star_red.gif' title='평점'> <font style='font-size:17px;font-weight:bold;'>".$es_store['fd_point_naver']."</font> <img src='../img/ic_bm.gif' title='방문자'> ".number_format($es_store['fd_point_visit'])." &nbsp;<img src='../img/bul59.gif' title='블로그리뷰'> ".number_format($es_store['fd_point_blog'])."";
																					

																						 #$mode_no=$k%1;		 #분기,나누기
																						 #if($k==1) echo "<td valign=top><table><tr style='background-color:yellow;'><Td >".$cur_title." 이름</td><td>대표메뉴</td></tr>";
																						  #if($mode_no==0 and $k>1) echo "</td></table></td> <td valign=top><table><tr style='background-color:yellow;'><Td>맛집이름</td><td>대표메뉴</td></tr>";

																						$Vals=array('store_no'=>$es_store['store_no']);
																						$get_src_tag=get_src_tag($Vals,$connect);

																					 echo "	<tr style='font-size:25px;'>
																					 <Td style='padding-top:5px;padding-bottom:5px;'><a href='".$es_store['fd_store_address_url']."' target='_blank' style='color:blue;'><img src='../img/fd.png'></a>
																					 ".$es_store['fd_store_name'].$point_tag."  </td><td>

																					<font style='font-size:17px;'>".nl2br($es_store['fd_store_cts'])."</td></tr>";																				

																					 echo "<tr>$dot_line</tr>";		
																					}

								 echo "</table>";




					  echo "</table>"; # <!-- end of table 002 -->
					  
echo "</td></tr>";  






echo "	  	
      </table>  <!-- start of table 000 -->
      ";

echo "</td>";

echo"
</tr>
</table>";

echo "</body></html>";

 ################### end of Id_Read_fnc #######################
}
################### end of Id_Read_fcc #######################






############################################
function  esguide_hobby_list ($connect) {   ############### 취미 리스트
###########################################

global $admin_info;
global $cur_php;
global $gs_type_cookie;

require "../env/inf.fnc";
require "../env/e.fnc";

  #변수할당

$GR_Vals=Get_Request_Post('mode');

$tbl_width=array('t'=>"1060px;",'m'=>"700px;",'s'=>"360px;");


		 $Vals_List=array('disp'=>"list",'hb_type'=>$GR_Vals['hb_type']);
         $hb_hdr=Hobby_Header($Vals_List,$connect);
		  if(!$GR_Vals['hb_type']) $GR_Vals['hb_type']=$hb_hdr['hb_type'];

		#$cur_title=$cate_hdr['str'][$GR_Vals['gs_type']];


  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>취미 모음</title>    
			 $style_css




	<script language=\"javascript\">

  function open_popup(url,pop_name){ 

						var popupX=	Math.min(event.screenX,window.screen.width / 2)-20;
						var popupY=	Math.min(event.screenY,window.screen.height / 2)-30;					

						if(pop_name=='utube') {

							  wth=1000;
							  hgt=1000;

						}							
																																		 
						 var size ='width='+wth+',height='+hgt+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 

						var n=open(url,name,size); 
						n.focus(); 						
						}

	 </script>

	";





echo "
        </head>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

        <table width=".$tbl_width['t']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\" bgcolor=white> <!-- start of table 000 -->";

			#헤더
					echo"<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>";
					echo $hb_hdr['tags'];

					echo "</td></tr>";

echo "		
        <tr>
        <td valign=top width=".$tbl_width['m']."> 
				
							<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";

							if($admin_info['usr_level']==1) $new_write="<img src='../img/ic/ic_pen02.gif'style='cursor:hand' title='신규 등록' onclick=\"location.href='$cur_php?mode=eh_wr&hb_type=".$GR_Vals['hb_type']."'\"></a>";
					else  $new_write="no";


					echo "<tr style='background-color:yellow;'>
					<td>".$new_write."</td>
						  <td colspan=2><img src='../img/ic/ic_meet.gif'>제목</td>

							  <td>키워드</td>
						  <td>등록일</td>
						 </tr>	  
					 ";

              if($GR_Vals['srch_word'])  $srch_qry= "and hb_tag Like '%".$GR_Vals['srch_word']."%' ";

						  $query=		"select * from esguide_hobby where hb_type='".$GR_Vals['hb_type']."'  ".$srch_qry." ";	  
						  $result=mysqli_query($connect,"$query");     
						  
					while($hb_src = mysqli_fetch_array($result, MYSQLI_ASSOC)){
						$rr++;

             	               $src_view_tag="<a href='$cur_php?mode=eh_view&src_no=".$hb_src['src_no']."'>";

																	#리뷰가져오기
											  $Vals=array('root_no'=>$hb_src ['src_no'],'root_type'=>'es_hobby');
					                          $get_story_tag= get_story_tag($Vals,$connect);
											  if($get_story_tag['review']) $review_tag=" <tr><td></td><td>".$src_view_tag.$get_story_tag['review']."</td></tr>";
											  else $review_tag="<tr><td></td></tr>";

					echo "<tr align=\"LEFT\" valign=\"MIDDLE\" height=30px;>

									  <td align=left style='padding-top:5px;font-size:14px;'>
									  $rr
									  </td>

									  <td align=left style='padding-top:5px;font-size:17px;'>
											".$src_view_tag.$hb_src['src_title']."
											<a href='#' onclick= open_popup('".$hb_src['src_url']."','utube') style='color:blue;'><img src='../img/utube.png'></a>											
											</td>
										<td>
										".$hb_src['hb_tag']."					
										</tD>
										
									  <td align=left style='padding-top:5px;font-size:12px;'>
									  ".$hb_src['uDate']."				 
									  </td>
									 </tr>				 
									 ";

                    echo $review_tag;

						}

					echo "	  	
						  </table> 
      ";
echo "</td>";
echo " <td valign=top width=".$tbl_width['s'].">"; 

$Vals_tag=array('hb_type'=>$GR_Vals['hb_type']); 
make_Hobby_tag_list($Vals_tag,$connect);


echo "
</td></tr>

</table>  <!-- start of table 000 -->"; 

echo "</body></html>";

 ################### end of esguide_hobby_list  #######################
}
################### end of esguide_hobby_list  #######################


############################################
function  esguide_hobby_view ($connect) {   ############### 취미 소스 보기
###########################################

global $admin_info;
global $cur_php;

require "../env/inf.fnc";
require "../env/e.fnc";

  #변수할당
$tbl_width=array('t'=>"1060px;",'m'=>"700px;",'s'=>"360px;");


$GR_Vals=Get_Request_Post('mode');


      $query="select * from esguide_hobby where src_no=".$GR_Vals['src_no'];
      $result=mysqli_query($connect,"$query");     
      if($result) { $hb_src = mysqli_fetch_array($result, MYSQLI_ASSOC);	                      
						}
	  

$today_ptime=calender_str(1,0,time());
$start_time=$today_ptime['unix_str'];




 
if($GR_Vals['mode_two']=='del') { # src 삭제

 $query_del="delete from esguide_hobby where src_no='".$GR_Vals['src_no']."' ";
 $result=mysqli_query($connect,"$query_del");                      

     echo "<body onload=location.href='$cur_php?mode=eh_list&hb_type=".$hb_src['hb_type']."';>       ";
	 exit;

}


		 $Vals_List=array('disp'=>"list",'hb_type'=>$GR_Vals['hb_type']);
         $hb_hdr=Hobby_Header($Vals_List,$connect);


if($admin_info['usr_level']==1) $modify_tags="| <a href='$cur_php?mode=eh_wr&src_no=".$hb_src['src_no']."&hb_type=".$hb_src['hb_type']."'>수정 | <a href='#' onclick=\"myconfirm('".$cur_php."?mode=eh_view&mode_two=del&src_no=".$GR_Vals['src_no']."','삭제 하시겠습니까?'); return false;\" ><img src='../img/ic/12-em-cross.png'>삭제</a> ";
else $modify_tags="";

  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>취미 모음</title>    
			 $style_css
			 $calender_js
        </head>

		
	<script language=\"javascript\">



  function open_popup(url,pop_name){ 

						var popupX=	Math.min(event.screenX,window.screen.width / 2)-20;
						var popupY=	Math.min(event.screenY,window.screen.height / 2)-30;					

						if(pop_name=='utube') {

							  wth=1000;
							  hgt=1000;

						}							
																																		 
						 var size ='width='+wth+',height='+hgt+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 

						var n=open(url,name,size); 
						n.focus(); 						
						}

					function      submit_popup(v) {		

																												
																																																				
													   if(v.tit.value=='' || v.tit.value=='내용을 적어주세요') { 
																											                                            alert('내용없음'); 
																										                                                return;
																																						}  // 제목이 없으면 등록 취소




					window.open('', 'pop_title','width=10, height=10') ;
							
					v.target = 'pop_title';
					v.action = '".$cur_php."';
					v.submit() ;

								
																									
																									
																									//		  v.submit();																											
																						
																																																																	
																							}




	 </script>

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

                <table width=".$tbl_width['t']." align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"1\" bgcolor=white> <!-- start of table 000 -->
			<tr valign=\"MIDDLE\"><td style='padding-top:5px;padding-bottom:5px;padding-left:25px;font-size:30px;' colspan=10>

".$hb_hdr['tags']."

</td></tr>

<tr>".$dot_line."</tr>

        <tr>
        <td valign=top> 
        ";


											echo "					
										<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"1\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:12px;'>";

											echo "<tr align=\"LEFT\" valign=\"MIDDLE\">
															  <td align=left style='padding-bottom:5px;font-size:20px;' width=100px;>
															   제목
																</tD>																
															  <td align=left style='padding-bottom:5px;font-size:20px;height:35px;'>
															  <a href='#' onclick= open_popup('".$hb_src['src_url']."','utube') style='color:blue;'><img src='../img/utube.png'> ".$hb_src['src_title']."</a>											
															  </td>

															  <td> <a href='#' onclick= open_popup('".$hb_src['src_url_add']."','utube') style='color:blue;'>".$hb_src['src_title_add']."</a>											</td>
															 </tr>				 
															 ";


														echo "<tr align=\"LEFT\" valign=\"MIDDLE\">
															  <td align=center style='padding-top:25px;padding-bottom:25px;padding-left:1px;font-size:12px;' colspan=10>
															  <a href='$cur_php?mode=eh_list&hb_type=".$hb_src['hb_type']."'>List ".$modify_tags."
																</td>
															 </tr>				 
															 ";


											echo"<tr align=\"LEFT\" valign=\"MIDDLE\">".$dot_line."</tR>";



                                            #메모 등록



																						echo"<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>";
																						
																						  echo "  <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																													<input type=hidden name=mode  value='es_story_up'>
																																													<input type=hidden name=root_type  value='es_hobby'>
																																													<input type=hidden name=root_no  value='".$hb_src['src_no']."'>
																																													<img src='../img/micon1.gif'>코멘트&nbsp; <input type='text' name=tit  id='tit' value='내용을 적어주세요' size='68'  class=form_nc $auto_clear_tag style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																																													<input type='text' name='uDate'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform.start_time','','0');\" style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;cursor:hand'>
																																													<input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_popup(document.myform)\"></form>";

																						
																						echo "</td></tR>";


										  ## 메모 남기기
										  					  $Vals=array('root_no'=>$hb_src['src_no'],'root_type'=>'es_hobby');
															  $get_story_tag= get_story_tag($Vals,$connect);
															  

										echo"<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>";

												echo $get_story_tag['tag'];
										

											echo "</td></tr>";


															 
											echo "	  	
												  </table>  
												  ";

echo "</td>";


				echo"
						<td valign=top width=".$tbl_width['s']."> ";
				# 번호가 있다면.. 게시물 내용을 불러올 것
				 $Vals_tag=array('hb_type'=>$hb_src['hb_type']); 
				make_Hobby_tag_list($Vals_tag,$connect);

				echo "</td>";


echo "

</tr>

</table> <!-- start of table 000 -->";

echo "</body></html>";

 ################### end of esguide_hobby_view  #######################
}
################### end of esguide_hobby_view  #######################




################### start   of esguide_hobby_write #########################
function esguide_hobby_write($connect) {  #### 취미 출처(SNS)입력
################### start   of esguide_hobby_write ########################
global $admin_info;
global $cur_php;
global $mobile;
require "../env/e.fnc";
require "../env/inf.fnc";

if($admin_info['usr_level']!=1) { echo "error"; exit; }

$GR_Vals=Get_Request_Post('mode');

if($mobile) {
					$font_size=array('r'=>"30px;",'h'=>"40px;",'t'=>"50px;"); 
                    $tbl_wth=array('m'=>"100%",'s'=>"30");
					$mobile_submit="&nbsp; <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;height:".$font_size['h'].";font-size:".$font_size['r']."'>";

					}
else  { $font_size=array('r'=>"12px;",'h'=>"15px;",'t'=>"35px;"); 
$tbl_wth=array('m'=>"980px",'s'=>"60"); 
}

      $query="select * from esguide_hobby where src_no=".$GR_Vals['src_no'];
      $result=mysqli_query($connect,"$query");     
      if($result) { $hb_src = mysqli_fetch_array($result, MYSQLI_ASSOC);	                      
						  $GR_Vals['hb_type']=$hb_src['hb_type'];
						} 

  	     $chk_array[$GR_Vals['hb_type']]="checked";	                     
    
		 $Vals_List=array('disp'=>"list",'hb_type'=>$GR_Vals['hb_type']);
         $hb_hdr=Hobby_Header($Vals_List,$connect);

 $open_pop_tags="<img src='../img/utube.png' onclick=\"open_popup('".$hb_src['src_url']."','n')\" style='color:blue;cursor:hand;' >";

  #변수할당


  $all_cat=all_category_tag($connect);
  $hb_str=$hb_hdr['str'];

$hb_type_tag="<table style='font-size:".$font_size['h']."'><tr><input type=\"hidden\" name=\"hb_type\" id=\"hb_type\" value='".$hb_hdr['hb_type']."'>";
foreach($all_cat as $ac_key => $ac_title)  {
	  $sn++;
	  if($hb_hdr['hb_type']==$ac_key) { $font_color_tag="color:red;font-weight:bold;"; $sel_img="<img src='../img/ic/16-heart-red-l.png'> "; }
	  else { $font_color_tag=""; $sel_img=""; }
	$hb_type_tag.="<td  style='padding-right:15px;'><a onclick=\"click_checked('".$sn."','".$ac_key."','hb_type');\" style='cursor:hand;".$font_color_tag."' name='hb_type_txt'><span name='hb_type_img'>$sel_img</span>".$ac_title."</a> </td> ";
}
$hb_type_tag.="</tr></table>";


  # 번호가 있다면.. 게시물 내용을 불러올 것	 
 
  echo"<meta charset='utf-8'>";

  echo "<html>

        <head>
             <title>".$cur_title." 리뷰</title>
    
			 $style_css


	<script language=\"javascript\">
     		 			 
	 function click_checked(click_sn,click_id,click_name) {

				 var click_name_id=click_name;
				 var ele = document.getElementById(click_name).value=click_id;
         
                var radio_txt=click_name+'_txt';
			    var ele_txt = document.getElementsByName(radio_txt);
				var click_id_no=click_sn-1; //

                var radio_img=click_name+'_img';
				var ele_img = document.getElementsByName(radio_img);			

			 for(var i=0; i < ele_txt.length; i++) {

				  ele_txt[i].style.color = \"black\";				
				  ele_txt[i].style.fontWeight = \"normal\";
				 ele_img[i].innerHTML='';
                       	
						if(i== click_id_no) { 						
						
						    ele_txt[i].style.color = \"red\";  //폰트색
							 ele_txt[i].style.fontWeight = \"bold\"; // 볼드체
							 ele_img[i].innerHTML='<img src=\"../img/ic/16-heart-red-l.png\"> ';
							 

						} 
						
				}
             
			}




			 function chkfrm(f) {	         
				
  if ( f.src_url.value=='')
     
                      {
		        alert('출처를 입력하세요!');
		        f.fd_name.focus();
		        return;
	              }


  if ( f.hb_type.value=='')
     
                      {
		        alert('구분을 입력하세요!');
		        f.hb_type.focus();
		        return;
	              }

		 

				 

    f.submit();	
	
      }


  function open_popup(url,pop_name){ 


						var popupX=	Math.min(event.screenX,window.screen.width / 2)-20;
						var popupY=	Math.min(event.screenY,window.screen.height / 2)-30;					

						wth=1000;
						hgt=1000;
																																 
						 var size ='width='+wth+',height='+hgt+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 


						 opt_win=pop_name;

						var n=open(url,opt_win,size); 
						n.focus(); 					
						
														
						}

	 </script>



	";




echo "
        </head>
     
        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" onLoad='document.myform.title.focus();' background=$bg_img>

     
        <table width=".$tbl_wth['m']." align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"1\"> <!-- start of table 000 -->
        <tr>
        <td align=center> 
        ";

echo ("					
		<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white style='font-size:".$font_size['r']."' >
 
<tr align=\"LEFT\" valign=\"MIDDLE\"><td colspan=10>

  ". $hb_hdr['tags']."
  
</td></tr>

<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"eh_up\">
     	<input type=\"hidden\" name=\"src_no\" value=\"".$hb_src['src_no']."\">

     <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;'>
       출처(URL)
      </td>
      <td colspan=3>
       <input type=text size='".$tbl_wth['s']."' name='src_url' id='src_url' style='height:".$font_size['t']." font-size:".$font_size['r']."' class=form_nc value=\"".$hb_src['src_url']."\">
	  &nbsp;
	  ".$open_pop_tags."
	  </td>
     </tr>	  

  
       <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=left style='padding-top:5px;font-size:".$font_size['r']."'>
       출처(타이틀)  	
      </td>
      <td colspan=3>
       <input type=text size='".$tbl_wth['s']."' name='src_title' id='src_title'  value=\"".$hb_src['src_title']."\" style='height:".$font_size['t']." padding-bottom:5px;font-size:".$font_size['r']."' class=form_nc>	  
	  </td>
     </tr>	 


 ");


#onclick= open_popup('".$hb_src['src_url']."','hb')
#onclick=javascript:openclub2('$hb_src['src_url']','width=1000,height=1500','get_thema')

if($mobile)  echo "<td colspan=4 style='font-size:".$font_size['h']."'>".$hb_type_tag."  ";  

else { echo"
      <td align=left style='padding-top:5px;'>
       구분
      </td>
      <td colspan=3 style='font-size:".$font_size['h']."'>
      ".$hb_type_tag."  
	  </td>";
 
}
echo"</tr>";	  



echo "
     <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td align=right style='padding-top:5px;' >
       <img src='../img/ico_tag.gif'>
      </td>
      <td colspan=3>

	   <input type=text size='15' name='hb_tag' id='hb_tag' class=form_nc value=\"".$hb_src['hb_tag']."\">
      <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:60px;'>
	  </td>
     </tr>";	 

if(!$mobile) {
echo"<tr><td colspan=4 style='padding-top:1px;padding-left:15px;padding-right:8px;' valign=top>
       <textarea name=src_cts id=\"src_cts\" style=\"width:100%; height:88px; overflow-x:hidden; overflow-y:auto;font-size:9pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc>".$hb_src['src_cts']."</textarea>
	   	   
	  </td></tr>";

echo "
     <tr align=\"LEFT\" valign=\"MIDDLE\">
      <td  style='padding-top:5px;' >
       추가정보
      </td>
      <td colspan=3>
	  url::
	   <input type=text size='15' name='src_url_add' id='src_url_add' class=form_nc value=\"".$hb_src['src_url_add']."\">
	  설명::
	   <input type=text size='15' name='src_title_add' id='src_title_add' class=form_nc value=\"".$hb_src['src_title_add']."\">

	  </td>
     </tr>";	 


}




echo "  			    </form>
 ";


echo "
      </table>  <!-- start of table 000 -->

	  </td>
      ";

echo " <td valign=top width=".$tbl_width['s'].">"; 

$Vals_tag=array('hb_type'=>$GR_Vals['hb_type']); 
make_Hobby_tag_list($Vals_tag,$connect);




echo "</td><tr>

</table>";


 ################### end of esguide_hobby_write#######################
}
#################### end of esguide_hobby_write #######################


################### start of esguide_hobby_update #######################
 function  esguide_hobby_update($connect) {  # 
################### start of esguide_hobby_update #######################
global $admin_info;
global $cur_php;
require "../env/e.fnc";

$test_on=0;
$GR_Vals=Get_Request_Post('mode');

#시작: 변수정의
	$up_qry="";

# 끝: 변수정의

$today= date("Y-m-d");   

if($test_on) print_r($GR_Vals);

 $skip_Array=array('src_no');

# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($GR_Vals as $pims_key => $pims_value) { # start of cust_val		 

  if(in_array($pims_key,$skip_Array)) continue;
#  if($pims_value=="") $pims_value=0;

  $up_qry.="$pims_key='$pims_value',";
}

$up_qry.="uDate='$today'";
# 끝: 받은 자료를 가지고 쿼리로 만듬

if($GR_Vals['src_no'])  $query="update esguide_hobby set  $up_qry where src_no=".$GR_Vals['src_no'];  
else  $query="insert into esguide_hobby set $up_qry"; 


$Vals=array('src_no'=>$GR_Vals['src_no'],'hb_type'=>$GR_Vals['hb_type'],'hb_tag'=>$GR_Vals['hb_tag']);
mng_hb_tag($Vals,$connect); #태그를 업데이트함

if($test_on) { echo $query; exit; }
else  $result=mysqli_query($connect,"$query");                      

if($result) {

				if(!$GR_Vals['src_no']) {
						$query="select max(src_no) as src_no from esguide_hobby";
						$result_src=mysqli_query($connect,"$query");
						$get_no = mysqli_fetch_array($result_src, MYSQLI_ASSOC);
						$GR_Vals['src_no']=$get_no['src_no'];
				}

			 Header("Location: ../pims/$cur_php?mode=eh_list&hb_type=".$GR_Vals['hb_type']."");
	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

#       mysql_close($connect);

#echo $query;
#exit;


################### end   of esguide_hobby_update #######################
} 
################### end   of esguide_hobby_update #######################





################### start of esguide_hobby_update #######################
 function  esguide_story_update($connect) {  # 
################### start of esguide_hobby_update #######################
global $admin_info;
global $cur_php;
require "../env/e.fnc";

$test_on=0;
$GR_Vals=Get_Request_Post('mode');

#시작: 변수정의
	$up_qry="";

# 끝: 변수정의

$today= date("Y-m-d");   

if($test_on) print_r($GR_Vals);

 $skip_Array=array('src_no');

# 시작: 받은 자료를 가지고 쿼리로 만듬
foreach ($GR_Vals as $g_key => $g_value) { # start of cust_val		 

  if(in_array($g_key,$skip_Array)) continue;

  $up_qry.="".$g_key."='".$g_value."',";
}

# 끝: 받은 자료를 가지고 쿼리로 만듬
$up_qry=substr($up_qry,0,-1);																				



if($GR_Vals['no'])  $query="update esguide_story set  $up_qry where no=".$GR_Vals['no'];  
else  $query="insert into esguide_story set $up_qry"; 


if($test_on) { echo $query; exit; }
else  $result=mysqli_query($connect,"$query");                      

if($result) {

   echo "<body  onload='javascript:self.close();opener.location.reload();'>";

			exit;

	        }
      else  { $message  = "Invalid query: " . mysql_error() . "\n". $query ;
		      die($message); 
			}

#       mysql_close($connect);

#echo $query;
#exit;





################### end   of esguide_hobby_update #######################
} 
################### end   of esguide_hobby_update #######################




# 스토리 가져오기
################### start    of get_src #######################
 function get_story_tag($Vals,$connect) { 
################### start    get_src  #######################

      $query="SELECT * FROM `esguide_story` where root_no='".$Vals['root_no']."' and root_type='".$Vals['root_type']."' "  ;
      $result_story=mysqli_query($connect,$query);     
	 # echo $query;

	  $tot_no=mysqli_num_rows($result_story);

$return_story_tag="<table style='font-size:12px;padding-top:5px;padding-bottom:5px;'>
							<tr><td align=center><img src='../img/calr.gif'> 등록일 </td><td> <img src='../img/B6.gif'> 코멘트 </td>

";




while($story_tag = mysqli_fetch_array($result_story, MYSQLI_ASSOC)){

	$rr++;
	    $up_day=calender_str(3,13,$story_tag['uDate']);	

	if($rr==1) {
		$review_tag="<table style='font-size:12px;color:red;'><tr><td><img src='../img/B6.gif'></td><td>(".$tot_no.")</td><td style='padding-left:15px;'> <img src='../img/calr.gif'> Last update:: ".$up_day['unix_str']."</td></tr></table>";

	}

	

    $up_day=calender_str(3,13,$story_tag['uDate']);	
	
	 $return_story_tag.= "<tr>
	 <td width=80px;>".$up_day['unix_str']."</td>
	 <td style='padding-top:10px;padding-bottom:10px;'><img src='../img/bm.gif'> ".$story_tag['tit']."</tD>
	 </tr>";
}

$return_story_tag.="</table>";







return array('tag'=>$return_story_tag,'review'=>$review_tag);


 ################### end of get_src  #######################
}
################### end of get_src  #######################









# 출처가져오기
################### start    of get_src #######################
 function get_src_tag($Vals,$connect) { 
################### start    get_src  #######################

      $query="SELECT * FROM `esguide_src_store`  as mfss left join esguide_src as mfs on mfss.src_no=mfs.src_no where mfss.store_no='".$Vals['store_no']."' "  ;
      $result_tag=mysqli_query($connect,$query);     

#<td> #".$src_tag['src_tag_area']."</tD>

$return_src_tag="<table style='font-size:12px;padding-top:5px;padding-bottom:5px;'>";

while($src_tag = mysqli_fetch_array($result_tag, MYSQLI_ASSOC)){

	$short_tit= shorten_Str($src_tag['src_title'],30,'..');

	 $return_src_tag.= "<tr><td>(출처)</td><td style='padding-top:5px;padding-bottom:5px;'><a href='$cur_php?mode=es_src_view&src_no=".$src_tag['src_no']."&gs_type=".$src_tag['gs_type']."' >".$short_tit."</td></tr>";

}

$return_src_tag.="</table>";

#  $query="select * from es_src_store as mfss left join esguide_store as mfs on mfss.store_no=mfs.store_no where mfss.src_no=".$GR_Vals['src_no']."";
#    $foreach_array=array($Vals['tag_area'],$Vals['tag_menu']);
#	$db_key_array=array('tag_area','tag_menu');

return $return_src_tag;


 ################### end of get_src  #######################
}
################### end of get_src  #######################



# 태그 리스트 만들기
################### start    of store_tag_list #######################
 function store_tag_list($Vals,$connect) { 
################### start    store_tag_list  #######################

   $db_key_array=array("tag_menu","tag_area");
   $db_srch_array=array("fd_store_tag_menu","fd_store_address");
   $title_array=array("메뉴별","지역별");

  				echo "<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=yellow style='font-size:12px;'>";
               echo "<tr>";

foreach($db_key_array as $db_k => $db_key) {
	$k=0;
	 
															 $query="SELECT * FROM `esguide_tag` where gs_type='".$Vals['gs_type']."' and ".$db_key." >''  " ;
															 $result_tag=mysqli_query($connect,$query);     

																			while($src_tag = mysqli_fetch_array($result_tag, MYSQLI_ASSOC)){

																					$src_tag_title=$src_tag[$db_key_array[$db_k]];
																					$srch_db_key=$db_srch_array[$db_k];																					

																					$srch_qry="";
																					
                                                                                    $chk_sep=explode('/',$src_tag_title);

																					if($chk_sep[1]) {
																						foreach($chk_sep as $cs_key=>$cs_title) {

																							$srch_qry.= $srch_db_key." Like '%".$cs_title."%' and ";
																						}																						
																						$srch_qry=substr($srch_qry,0,-4);																				

																					} else 		$srch_qry= $srch_db_key." Like '%".$src_tag_title."%' ";

																						if($srch_db_key=="fd_store_address") $srch_qry.=  "or street_name Like '%".$src_tag_title."%'";

																					 $query_srch="SELECT count(*) as tot FROM `esguide_store` where gs_type='".$Vals['gs_type']."' and (".$srch_qry.")  "  ;
																																							
																					  $result_srch=mysqli_query($connect,$query_srch);     

																					   $srch = mysqli_fetch_array($result_srch, MYSQLI_ASSOC); 

																					   $get_tot[$db_key][]=$srch['tot'];
																					   $get_title[$db_key][]=$src_tag_title;     	 				
																	  
																	}

														        if($get_title) {
																	
																	
																				array_multisort($get_tot[$db_key], SORT_DESC, $get_title[$db_key]);

																		   echo "<td valign=top>		
																										<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white width=100%;><tR>";

																																			
																																						   foreach($get_title[$db_key] as $gt => $gt_title) {

																																							   $k++;																				   
																																								$mode_no=$k%45;		 #분기,나누기
																																								 
																																								 if($gt_title==$Vals['srch_word']) { $sel_tag="<font style='color:red;font-weight:bold;font-size:13px;'>";  $sel_img="<img src='../img/micon2.gif'>"; }
																																								 else { $sel_tag="<a href=\"$cur_php?mode=es_store_list&gs_type=".$Vals['gs_type']."&srch_key=".$db_srch_array[$db_k]."&srch_word=".$gt_title."\">"; $sel_img=""; }

																																								 $del_tags="";

																																								 if(!$get_tot[$db_key][$gt]) $del_tags="<a href='$cur_php?mode=es_store_list&mode_two=del_tag&gs_type=".$Vals['gs_type']."&key_word=".$gt_title."'><img src='../img/ic/12-em-cross.png'></a>";


																																								 if($k==1) echo  "<td valign=top>
																																															<table border=0  style='font-size:12px;' width=130px;> <tr><td bgcolor=yellow colspan=2>".$title_array[$db_k]."</td></tr>";

																																									 echo "<tr><td width='5px;' align=right  style='padding-bottom:10px;' >".$sel_img."</td><td align=left style='padding-top:7px;padding-bottom:7px; ' >".$sel_tag.$gt_title."</a> (".$get_tot[$db_key][$gt].") $del_tags</td></tr>";

																																									  if($mode_no==0 and $k>1)echo   "</table></td><td valign=top><table border=0  style='font-size:12px;' width=130px;> <tr><td bgcolor=yellow colspan=2>".$title_array[$db_k]."</td></tr>";
																																									}

																						echo "</tr></table>";

																} # end of  if_get_title;

														   echo "</td>";

 }

echo "</tr>";

echo "</table>";


#return $return=array('start_no'=>$start_no,'pages_tag'=>$pages_tag,'cur_start_no'=>$cur_start_no);


 ################### end of store_tag_list  #######################
}
################### end of store_tag_list  #######################




# 태그 리스트 만들기
################### start    of make_tag_list #######################
 function make_Hobby_tag_list($Vals,$connect) { 
################### start    make_tag_list  #######################

$title_tag="메뉴별 Tag";


  				echo "<table width=\"100%\" border=\"0\" cellspacing=\"0\" cellpadding=\"0\"  bgcolor=yellow style='font-size:12px;'>";
               echo "<tr>";

					 $query="SELECT * FROM `esguide_hobby_tag` where hb_type='".$Vals['hb_type']."'>''  " ;
					 $result_tag=mysqli_query($connect,$query);     

						
								while($src_tag = mysqli_fetch_array($result_tag, MYSQLI_ASSOC)){
									 
																					 $srch_qry= "hb_tag Like '%".$src_tag['hb_tag']."%' ";

																					 $query_srch="SELECT count(*) as tot FROM `esguide_hobby` where hb_type='".$Vals['hb_type']."' and ".$srch_qry.""  ;
																					  $result_srch=mysqli_query($connect,$query_srch);     

																					  $srch = mysqli_fetch_array($result_srch, MYSQLI_ASSOC); 

																					   $get_tot[]=$srch['tot'];
																					   $get_title[]=$src_tag['hb_tag']; 																	  
																	}

					
														        if($get_title) {																
																	
																				array_multisort($get_tot, SORT_DESC, $get_title);

																				$title_hdr_tags="<table border=0  style='font-size:12px;' width=130px; border=\"0\" cellspacing=\"0\" cellpadding=\"0\" > <tr style='background-color:yellow;' height=37px;><td bgcolor=yellow colspan=2>".$title_tag."</td></tr>";

																		   echo "<td valign=top>		
																										<table border=\"0\" cellspacing=\"0\" cellpadding=\"0\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white width=100%;><tR>";

																																			
																																						   foreach($get_title as $gt => $gt_title) {

																																							   $k++;																				   
																																								$mode_no=$k%50;		 #분기,나누기
																																								 
																																								 if($gt_title==$Vals['srch_word']) { $sel_tag="<font style='color:red;font-weight:bold;font-size:13px;'>";  $sel_img="<img src='../img/micon2.gif'>"; }
																																								 else { $sel_tag="<a href=\"$cur_php?mode=eh_list&hb_type=".$Vals['hb_type']."&srch_word=".$gt_title."\">"; $sel_img=""; }

																																								# if(!$get_tot[$gt]) $del_tags="<a href='$cur_php?mode=e_list&mode_two=del_tag&gs_type=".$Vals['gs_type']."&key_word=".$gt_title."'><img src='../img/ic/12-em-cross.png'></a>";

																																								 if($k==1) echo  "<td valign=top>".$title_hdr_tags;

																																									 echo "<tr><td width='5px;' align=right  style='padding-bottom:10px;' >".$sel_img."</td><td align=left style='padding-top:7px;padding-bottom:7px; ' >".$sel_tag.$gt_title."</a> (".$get_tot[$gt].") $del_tags</td></tr>";

																																									  if($mode_no==0 and $k>1)echo   "</table></td><td valign=top>".$title_hdr_tags;
																																									}

																						echo "</tr></table>";

																} # end of  if_get_title;

														   echo "</td>";


echo "</tr>";

echo "</table>";


#return $return=array('start_no'=>$start_no,'pages_tag'=>$pages_tag,'cur_start_no'=>$cur_start_no);


 ################### end of make_tag_list  #######################
}
################### end of make_tag_list  #######################



# 페이지 인덱스 만들기
################### start    of get_pages #######################
 function get_pages($Vals,$connect) { 
################### start    get_pages  #######################

if(!$Vals['pages']) $Vals['pages']=1;

                                         $query="select *  from ".$Vals['db_name']." ".$Vals['srch_qry']." ";
									     $result_pages=mysqli_query($connect,$query);     
                                         $row_pages=mysqli_num_rows($result_pages);


  if($Vals['srch_word']) {

    while($get_pages = mysqli_fetch_array($result_pages, MYSQLI_ASSOC)){

		   $tag_menu_txt.= $get_pages['fd_store_tag_menu'].",";
	}
$tag_menu_txt=substr($tag_menu_txt,0,-1);
     $t_array= explode(',',$tag_menu_txt);


   $result_tag=array_count_values($t_array);

  arsort($result_tag);

    $mn=7;
   $rt_tag="<Table style='font-size:12px;color:white;'>";
    foreach($result_tag as $rt_key => $rt_value) {

		$rt++;
		$mode_no=$rt%$mn;

		if($rt==1) $rt_tag.="<tr>";
		  $rt_tag.= "<td>".$rt_key."(".$rt_value.") </td> ";
		if($mode_no==($mn-1)) $rt_tag.="</tr><tr>";

	}
   $rt_tag.="</tr></table>";

  }
										 
						       
$total_page = ceil($row_pages/$Vals['limit_no']);

 for($p=1;$p<=$total_page;$p++){ 

     $start_no[]=$Vals['limit_no']*($p-1);

	 if($Vals['pages']==$p) { $cur_start_no=$start_no[$p-1];  $font_tag="</a><font style='color:red;font-weight:bold;'>";}
	 else $font_tag="</font><a href='$cur_php?mode=es_store_list&gs_type=".$Vals['gs_type']."&pages=".$p."' style='color:yellow;'>";

	 $pages_tag.= $font_tag."[".$p."]</a> ";

 }

if($Vals['srch_word']) $pages_tag="<table><tr><td><font style='color:yellow;'>'".$Vals['srch_word']."' 관련된 음식점을 총 ".$row_pages." 개를 찾았습니다. &nbsp; [ <a href='$cur_php?mode=es_store_list&gs_type=".$Vals['gs_type']."' style='color:white;'>전체보기</a> ] <a onclick=\"window.open('".$cur_php."?mode=es_store_print&srch_word=".$Vals['srch_word']."&gs_type=".$Vals['gs_type']."&srch_key=".$Vals['srch_key']."','pop_hidden','width=1250, height=1000');\" style='cursor:hand;'>[print]</a></td></tr><tr><td>".$rt_tag."</td></tr></table>";



return array('start_no'=>$start_no,'pages_tag'=>$pages_tag,'cur_start_no'=>$cur_start_no,'tot'=>$row_pages);


 ################### end of get_pages  #######################
}
################### end of get_pages  #######################



# 전체 인명그룹 가져오기
################### start    of category_Header #######################
 function category_Header($Vals) { 
################### start    category_Header #######################
global $admin_info;

$gs_type_array=array(1=>"맛집",2=>"여행지",3=>"숙소");
$tit_array=array("리스트","출처(SNS)");


$qry_array=array("mode=es_store_list","mode=es_src_list");

if($Vals['disp']=="store") {  $qry=$qry_array[0];  
                                                  $tt_sel[0]="<font style='color:red;font-weight:bold;'>";
												  $tt_sel[1]="<a href='$cur_php?".$qry_array[1]."&gs_type=".$Vals['gs_type']." '>";
											}
	else if($Vals['disp']=="src") {  $qry=$qry_array[1]; 
	$tt_sel[0]="<a href='$cur_php?".$qry_array[0]."&gs_type=".$Vals['gs_type']." '>";
	$tt_sel[1]="<font style='color:red;font-weight:bold;'>";
	}


foreach($tit_array as $t_key=> $t_title) {

	   $tt_tags.=$tt_sel[$t_key].$tit_array[$t_key]."</a></font> | ";

}

 if($admin_info['usr_level']==1) $tt_tags.="<img src='../img/ic/ic_pen02.gif'style='cursor:hand' title='신규 등록' onclick=\"location.href='$cur_php?mode=es_src_write&gs_type=".$Vals['gs_type']."'\">";

$sc_tags= "<table><tr><td style='font-size:12px;'>".$tt_tags."</td><td width=100px;> </td>";

foreach($gs_type_array as $gt_key=> $gt_title) {

       
	     if($gt_key==$Vals['gs_type']) { $style_tag="style='font-weight:bold;color:red;>'"; $gt_title="[".$gt_title."]"; }
		 else { $style_tag="";  }

		   $href_tag="<a href='$cur_php?".$qry."&gs_type=".$gt_key." ' ".$style_tag.">";
	  
	   $sc_tags.= "<Td ".$style_tag.">&nbsp; ".$href_tag.$gt_title." </td>";

}




$sc_tags.= "</tr></table>";

return array('tags'=>$sc_tags,'str'=>$gs_type_array);


 ################### end of category_Header  #######################
}
################### end of category_Header  #######################

# 전체 인명그룹 가져오기
################### start    of category_Header #######################
 function Hobby_Header($Vals,$connect) { 
################### start    category_Header #######################

#$hb_type_array=array(1=>"요리레시피",2=>"생활팁",3=>"쇼핑",4=>"좋은글(감동)",5=>"만화",6=>"기타");

$all_cat=all_category_tag($connect);


$qry="mode=eh_list";

$hb_tags= "<table><tr height=40px;><td style='font-size:14px;padding-left:25px;'>Woong's Hobbies Life</td><td width=100px;> </td>";

if(!$Vals['hb_type'])  $Vals['hb_type']=array_key_first($all_cat); 


foreach($all_cat as $hb_key=> $hb_title) {
   
         if($hb_key==$Vals['hb_type']) { $style_tag="style='font-weight:bold;color:red;>'"; $hb_title="[".$hb_title."]"; }
		 else { $style_tag="";  }

		   $href_tag="<a href='$cur_php?".$qry."&hb_type=".$hb_key." ' ".$style_tag.">";
	       $hb_tags.= "<Td ".$style_tag.">&nbsp; ".$href_tag.$hb_title." </td>";

}

$hb_tags.="<td><a href='../prj_yehior.php?mode=cm&cat_type=hobby_gr'><img src='../img/plus.gif'style='cursor:hand' title='신규 등록'></td>";




$hb_tags.= "</tr></table>";

return array('tags'=>$hb_tags,'str'=>$hb_type_array,'hb_type'=>$Vals['hb_type']);


 ################### end of category_Header  #######################
}
################### end of category_Header  #######################




# 전체 인명그룹 가져오기
################### start    of mysql_Query #######################
 function mng_fd_tag($Vals,$connect) { 
################### start    mysql_Query #######################

    $gs_type=$Vals['gs_type'];
    $foreach_array=array($Vals['tag_area'],$Vals['tag_menu']);
	$db_key_array=array('tag_area','tag_menu');

	foreach($foreach_array as $tag_key=>$tag_value) {

		          if(empty($tag_value)) continue;
									 $tag_db_array=explode(',',$tag_value);
									 $srch_db_key=$db_key_array[$tag_key];
									
								 foreach ($tag_db_array as $td_key => $td_value) { # start of cust_val		 

 															  $query_srch="select * from esguide_tag where  ".$srch_db_key."='".$td_value."' and gs_type='".$gs_type."' " ;
															  $result_store_srch=mysqli_query($connect,$query_srch);     

														      $chk_no = mysqli_fetch_array($result_store_srch);
															
															  if(!$chk_no) {

																  $query_ins="insert into esguide_tag set  ".$srch_db_key."='".$td_value."', gs_type='".$gs_type."'   " ;
																  $result_ins=mysqli_query($connect,$query_ins);     
																  
															  } 


								 }

	}



################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################





################### start    of mysql_Query #######################
 function mng_hb_tag($Vals,$connect) { 
################### start    mysql_Query #######################

# 기존것 삭제
  $query_del="delete from esguide_hobby_tag where  src_no='".$Vals['src_no']."' " ;
  $result_del=mysqli_query($connect,$query_del);     


   $hb_tag=explode(',',$Vals['hb_tag']);

 foreach($hb_tag as $hb_key => $hb_tag_value) {															  

															  $query_srch="select * from esguide_hobby_tag where  hb_tag='".$hb_tag_value."' and hb_type='".$Vals['hb_type']."' " ;
															  $result_store_srch=mysqli_query($connect,$query_srch);     

														      $chk_no = mysqli_fetch_array($result_store_srch);
															  #echo $query_srch;
															  # print_r($chk_no);
															  															
															  if(!$chk_no) {

																  $query_ins="insert into esguide_hobby_tag set  src_no='".$Vals['src_no']."',hb_tag='".$hb_tag_value."', hb_type='".$Vals['hb_type']."'   " ;
																  $result_ins=mysqli_query($connect,$query_ins);     															  
																#  echo $query_ins;
															  }


			
								 }



################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################







################### start    of mysql_Query #######################
 function all_category_tag($connect) { 
################### start    mysql_Query #######################

# 기존것 삭제
  $query="select * from tbl_cat where cat_type='hobby_gr' order by cat_ord_no " ;
  $result=mysqli_query($connect,$query);     


while($all_cat = mysqli_fetch_array($result, MYSQLI_ASSOC)){

    $return_cat[$all_cat['no']]=$all_cat['cat_str'];

}

return $return_cat;



################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################






?>