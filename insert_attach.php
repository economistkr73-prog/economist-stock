<?php							   

#변수정의
$mode = $_REQUEST["mode"];
#변수정의


#echo $mode;


if($mode=='up_dir_file') Up_Dir_File_Script();
else if($mode=='get_dir_file') Display_Tag_Dir();
#else if($mode=='dn_dir_file') Dn_Dir_File();


# 불러오는쪽 상단 자바스크립트에 함수 2개 추가한후 불러 올것



###########################################################
function Up_Dir_File_Script() {
###########################################################
require "./env/e.fnc";



  #변수할당
  $get_file=Get_Vals('mode');



  # 변수 할당


$tmp_name=$_FILES['local_file']['tmp_name'];

$thumb_width=300;

$_FILES['local_file']['name']= preg_replace("/\s\s+/","",$_FILES['local_file']['name']);


$Ext_Array = explode(".",$_FILES['local_file']['name']); 
$Ext_Array_Num=count($Ext_Array);
$Hdr_Name_Str= $Ext_Array[$Ext_Array_Num-2];
$Ext_Str= $Ext_Array[$Ext_Array_Num-1];

$unix_time=time();

$new_file_name="tempUpFile_".time().".".$Ext_Str;	 # 번호 할당은 실제 db 업데이트할때 함						
	
if(!$tmp_name) { exit; }

if(!$get_file[fname]) $display_name=$Hdr_Name_Str;
	else $display_name=$get_file[fname];





$file_ext_info=chk_file_icon($Ext_Str); # 이미지 여부 체크  [0] 아이콘이미지, [1] 이미지여부  1: 이미지
$Is_Img_File=$file_ext_info[1];


            if($Is_Img_File) {
							   list($img_width, $img_height) = getimagesize($tmp_name); 

                               # 만약 가로가 700보다 크거나 높이가 700보다 크다면.. 사이즈 조정이 필요함

                                 $max_width=700;
								 $max_height=900;

							    $Get_Resize = Resize_To_ImAge($img_width,$img_height,$max_width,$max_height);

								$resize_Width=$Get_Resize[0];
								$resize_Height=$Get_Resize[1];

               	   	  	     }


							$Make_Attach_File_Tag=$file_ext_info[1]."&%&".$Ext_Str."&%&".$display_name."&%&".$new_file_name."&%&$get_file[dir_st]/&%&".$resize_Width."&%&".$resize_Height."&^^^&";

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


#echo "$Make_Attach_File_Tag  <br> $svr$uploadDir/$new_file_name";


#exit;

echo "

<script>

	var tmpOpener = window.opener;  // opener정의
	 tmpOpener.attach_file_fnc('".$display_name."','".$Make_Attach_File_Tag."');    			   		
	 self.close();
</script>	 
	";		   
					   
exit;
##############################################################
} # end of Up_File_Script
##############################################################




##############################################################
function Display_Tag_Dir() {
##############################################################
# 		
require "./env/e.fnc";

  #변수할당
 $get_file=Get_Vals('mode');

 
#print_r($get_file);

  # 변수 할당
 
#
echo "
<html STYLE=\"width:320px; height: 130px; \">
<head><title>파일 첨부하기</title><head>
      <meta charset='utf-8'>


</HEAD>

<BODY scroll=no>
<table height=100% width=100% border=\"0\" cellspacing=\"0\" cellpadding=\"0\" >
<tr><td align=cneter>

<table border=\"0\" cellspacing=\"6\" cellpadding=\"0\">
<tr><td>
	<table border=\"0\" cellspacing=\"2\" cellpadding=\"0\">
	<tr><td>
		<table border=\"0\" cellspacing=\"2\" cellpadding=\"0\" style='font-size:12px;' >
     <form name=\"insert_attach\" method=\"post\" enctype=\"multipart/form-data\" > 
     <input type=\"hidden\" name=\"mode\" value='up_dir_file'>
     <input type=\"hidden\" name=\"dir_st\" value='$get_file[dir_st]'>

		<tr><td><첨부파일></td><td><input type='submit' class='form_nc' value='추가'  style='width:100'>		</td></tr>
				<tr>
		<td colspan=2><파일설명><INPUT type=text name='fname'  onfocus=\"select();\" style='width:200' class='form_nc'></td>
		</tr>
		<tr>
		<td colspan=2><찾아보기><INPUT type=file name='local_file'  onfocus=\"select();\" style='width:200' class='form_nc'></td>
        
		</tr>
	
	  <tr style='display:none'><td><iframe src='blank.html' name=submitframe></iframe></td></tr>

 </form>


		</table>
	</td><td>

	</td></tr>
	</table>	
</td></tr>

</table>

</td></tr>
</table>


</BODY>
</HTML>";

###################################
} # insert_img
###################################



?>