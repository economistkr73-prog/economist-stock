<?php

# DB연결
require "./env/cnt.inc";
require "./env/e.fnc";

# error 표시
 error_reporting( E_ALL& ~E_NOTICE ); # ~E_NOTICE
 ini_set( "display_errors", 1 );
ini_set("allow_url_fopen",1);


#변수정의


$cur_php = basename($_SERVER['PHP_SELF']);

$GR_Vals=Get_Vals('');


if($GR_Vals['mode']) $mode=$GR_Vals['mode'];  else $mode='news_read';

if($GR_Vals['key_word']) $key_word=$GR_Vals['key_word'];  else $key_word="특징주";

$max_pages=$GR_Vals['max_pages'];
$G_thema_no=$GR_Vals['thema_no'];
$G_stock_code=$GR_Vals['stock_code'];
$adminID=0;

#변수정의



#print_r($_POST['thema']);

#exit;

if($mode=='write')              { thema_Write ($connect); }

elseif($mode=='read')              { theme_Read ($connect); }

elseif($mode=='update')         { theme_update($connect); }

elseif($mode=='prg')         { theme_prg($connect); }

elseif($mode=='delete')         { theme_delete($connect); }

elseif($mode=='memo_update')         { theme_Memo_update($connect); }

elseif($mode=='memo_prg')         { theme_Memo_prg($connect); }

elseif($mode=='memo_delete')         { theme_Memo_delete($connect); }



# 테마 뉴스를 구글에서 가져오고, DB에 등록된 정보를 가져옴


elseif($mode=='thema_list')              {  thema_list ($connect); }

elseif($mode=='news_read')              {  thema_news_read ($connect); }


# 엑셀로 만든 테마 파일 업데이트 (.txt)
#elseif($mode=='file_attach')              {  thema_file_attach ($connect); }

#  전종목시세 업데이트
elseif($mode=='all_stock_attach')              {  thema_all_stock_attach ($connect); }


# 파일 읽고 종목코드,데일리 테마종목 입력.
elseif($mode=='thema_all_stock_up')              { 
	                                                                                            thema_all_stock_update ($upfile,$connect); }




# 파일 읽고 종목코드,데일리 테마종목 입력.
elseif($mode=='thema_file_up')              {    $thema=$_POST['thema'];
	                                                                                thema_file_update ($adminID,$thema,$upfile,$connect); }


# 파일 읽고 종목코드,데일리 테마종목 입력.
elseif($mode=='thema_daily_story_up')              { 

																												$thema_story=$_POST['thema_story'];

																												thema_daily_story_update ($adminID,$thema_story,$connect); 
																						      }





elseif($mode=='news_scrap')              { news_scrap($connect); }


else  {  echo "<script language=\"javascript\">
    			alert(\" Version : $ver \");
    			</script>    			
    			";			
		}

mysqli_close($connect);





#################################################################
function thema_news_read ($connect) {
#################################################################

require "./env/inf.fnc";

global  $cur_php;
global  $GR_Vals;
global $key_word;
global $max_pages;
global $G_thema_no;
global $G_stock_code;


#print_r($GR_Vals);

# 테마번호가 있는 경우에는 테마주를 넣어서 구글에서 검색
#if(!empty($G_thema_no) or !empty($G_stock_code) or !empty($max_pages)) 
	$add_str=" 테마주 특징"; 


# " ,' 등 특수문자제거

$key_word= preg_replace("/[ #\&\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "",  strtoupper($key_word));


$g_key_word= str_replace(' ',"+",$key_word.$add_str);

#print_r($g_key_word);

$google_srch_Tags="<a href='https://www.google.com/search?q=".$g_key_word."'  target=_blank>구글검색?</a>";
#echo $google_srch_Tags;


   $srch_ins_array=array('opt'=> "insert",  'key_word'=> $key_word, 'stock_code'=> $G_stock_code);
	  search_history($srch_ins_array,$connect);

$srch_get_array=array('urls'=>"$cur_php?mode=news_read&key_word=", 'limit_no'=> '12');

$srch_history_tags=search_history($srch_get_array,$connect);

include "shd/simple_html_dom.php";

											## 시작 : 최근에 등록한 테마 리스트

											$thema_key_word="";

																						$query_thema="SELECT * FROM `tbl_thema_name` order by uDate desc limit 15 ";
																						$result_thema=mysqli_query($connect,$query_thema); 


																					 while($thema_str=mysqli_fetch_array($result_thema)) {


																											   $thema_key_word.="<a href=$cur_php?mode=news_read&key_word=".$thema_str['thema_name']."&thema_no=".$thema_str['thema_no'].">".$thema_str['thema_name']."</a> | ";		
																								}


																					  $thema_list_tags= "<img src='../img/a1.gif'><a href='daily_pax_thema.php?mode=thema_manaGe' target='_blank' target='news2'>[ 테마]</a> ". $thema_key_word;

																			
											## 끝 : 가장 최근에 등록한 테마 리스트

										
											## 시작 : 가장 최근에 등록한 종목명


											                                          $stock_code_key_word="<table class=n1s>";


																						$query_stock_code="SELECT DISTINCT  tsc.stock_code,tsc.uDate,tdts.thema_no,ttn.thema_name FROM `tbl_daily_thema_stock` AS tsc  LEFT OUTER JOIN `tbl_daily_thema_stock` AS  tdts ON tdts.stock_code = tsc.stock_code  left join tbl_thema_name as ttn on tdts.thema_no = ttn.thema_no where tsc.uDate = (SELECT uDate FROM `tbl_daily_thema_stock` order by uDate desc limit 1 )";

																						#echo $query_stock_code;
																						
																						$result_stock_code=mysqli_query($connect,$query_stock_code); 

																						  # 결과값을 테마로 배열정렬함
																						 while($stock_code_str=mysqli_fetch_array($result_stock_code))  {  $stock_thema_array[$stock_code_str['thema_no']][]=$stock_code_str;  
																						 
																						                                                                                                                                   }

																						 sort($stock_thema_array); # 테마명으로 정렬

                                                                                             
																						
																						foreach( $stock_thema_array as $stock_array) {

																								
																							   if(!empty($stock_array[0]['thema_no']))      $stock_code_key_word.= "<tr><td width=180><img src='../img/cb.gif'><a href=$cur_php?mode=news_read&key_word=\"".$stock_array[0]['thema_name']."\"&thema_no=".$stock_array[0]['thema_no']."><font  style='color:red'> ".$stock_array[0]['thema_name']." </font></td><td> ";
																							   else $stock_code_key_word.= "<tr><td><img src='../img/cb.gif'><font  style='color:black'> 개별 </font></td><td> ";


																								for($ti=0;$ti<count($stock_array);$ti++)	 {

        																						 $query_get_stock_code="select stock_name from all_stock_info where stock_code='".$stock_array[$ti]['stock_code']."'  ";
																								$result_get_stock_code=mysqli_query($connect,$query_get_stock_code); 
																								$get_stock_code_str=mysqli_fetch_array($result_get_stock_code);
																											 
																																$stock_code_key_word.= "<a href='$cur_php?mode=news_read&key_word=\"".$get_stock_code_str['stock_name']."\"&stock_code=".$stock_array[$ti]['stock_code']."&thema_no=".$stock_array[$ti]['thema_no']."'>".$get_stock_code_str['stock_name']."</a>  " ; 

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

                      

					                        if($G_thema_no and $G_thema_no!=0) {

																						$query_thema_daily_stock="SELECT *, tdts.uDate as rDate from tbl_daily_thema_stock as tdts left join tbl_stock_code as tsc on tdts.stock_code= tsc.stock_code where tdts.thema_no='$G_thema_no' order by tdts.stock_code,tdts.uDate desc";
																						$result_thema_daily_stock=mysqli_query($connect,$query_thema_daily_stock); 


																					 while($thema_daily_stock_array=mysqli_fetch_array($result_thema_daily_stock)) {

																											   $thema_daily_stock_word.= "<tr><td width=80>".$thema_daily_stock_array['rDate']."</td><td><a href=\"$cur_php?mode=news_read&key_word='".$thema_daily_stock_array['stock_name']."'&stock_code=".$thema_daily_stock_array['stock_code']."&thema_no=".$thema_daily_stock_array['thema_no']."\">".$thema_daily_stock_array['stock_name']."</td></tr><tr>$dot_line</tr> ";		
																								}


																					  #$thema_list_tags= "[특징테마] ($thema_day) ". $thema_key_word;

																		$thema_daily_stock_tags=	  "<table class=n1s>".$thema_daily_stock_word."</table>";

											}



											#echo $thema_daily_stock_tags;

											## 끝 : 테마 코드가 있다면... 테마 종목 테이블에 등록된 내용들 가져올것

											## 구글에서 한번에 10개씩 가져와서 

											if($max_pages==1) 	$start_num_array=array(0,10,20,30,40);  # 폼input을 통한 검색(특징주)인 경우 최대 50개까지 보여줄것
																			else     $start_num_array=array(0,10,20);


																			$as=1;

																			$query_del_news="delete from tmp_news ";
                                                                             $result_del_news=mysqli_query($connect,$query_del_news); 


																		foreach($start_num_array as $start_num) { # start of foreach 001
																																															
																																															   ## https 는 안됨.  뉴스와 전체인경우 구분해야 함. 어떻게?


                                                                                                                                                                                                 #  $google_url ="http://www.google.com/search?q='".$g_key_word;

																																																$google_url ="http://www.google.com/search?q='".$key_word."'&tbas=1&biw=1449&bih=1562&tbs=qdr:w&tbm=nws&start=$start_num&lr=lang_ko";

																																																#echo $google_url;

																																																$get_html=file_get_html($google_url);
																																																

																																				
																																						foreach($get_html->find('div.Gx5Zad.fP1Qef.xpd.EtOod.pkphOe') as  $link_res) { # start of foreach 002
																																																   

																																																																						  $link=$link_res->find('a',0)->href;

																																																																						  $src_site=$link_res->find('div.BNeawe.UPmit.AP7Wnd',0)->plaintext;

																																																																						   $title=$link_res->find('div.BNeawe.vvjwJb.AP7Wnd',0)->plaintext;

																																																																						   $ori_link ="https://google.com".$link;

																																																																						   

																																																																						   
																																																																							$up_day=$link_res->find('span.r0bn4c.rQMQod',0)->plaintext;

																																																																						   #


																																																																						  # 구글에서 한글페이지만 검색하는 옵션 lr=lang_ko 을 선택했을때.. euc-kr로 리턴되는 듯. 이를 다시 utf-8로 변경

																																																																						  $title = iconv("EUC-KR", "UTF-8", $title);
																																																																						  $ori_title=addslashes($title);

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

																																																																								#  종목코드 또는 테마코드가 있는 경우에 따로 따로 등록하는게 좋을듯..



																																																																								if(!empty($G_stock_code)) 	$input_stock_tag="<span id='btn_stock_$as'><a href=javascript:openclub2('$cur_php?mode=news_scrap&opt=stock_code&tmp_no=$as','width=1,height=1','hiddenframe1');hide_button('btn_stock',$as);><img src='../img/star_red.gif' alt='스크랩'></a></span>";

																																																																								if(!empty($G_thema_no)) $input_thema_tag="<span id='btn_thema_$as'><a href=javascript:openclub2('$cur_php?mode=news_scrap&opt=thema_no&tmp_no=$as','width=1,height=1','hiddenframe1');hide_button('btn_thema',$as);><img src='../img/star_b.gif' alt='스크랩'></a></span>";

																																																																								#echo "$cur_php?mode=news_scrap&opt=thema_no&tmp_no=$as";


																																																																																						 
																																																																							
																																																																							$query_ins_news="insert into tmp_news set  stock_code='$G_stock_code',thema_no='$G_thema_no',news_title='$ori_title',news_link='$ori_link',tmp_no=$as ";

                                                                                                                                                         																																	$result_ins_news=mysqli_query($connect,$query_ins_news); 

																																																																							#echo $query_ins_news;
																																																																							#echo "<br>";



																																																																							## 뉴스 목록을 보여줌
																																																																							  

																																																																								$title=str_replace($GR_Vals['key_word'],"<font style='color:black;font-weight:bold;font-size:20px;'>".$GR_Vals['key_word']."</font>",$title);
																																																																								
																																																																							


																																																																							$Find_Link[$up_day_num][]="[$src_site] <a href='".$ori_link."'  target='news1' style='font-size:17px;'>$title</a> $up_day&nbsp; &nbsp; <a href='".$ori_link."'  target='news2' style='font-size:17px;'><img src='../img/imoticon/num4/02.gif'></a> $input_stock_tag   $input_thema_tag";

																																																																						  #  $Find_Link[] = array('up_day' => $up_day_num, 'cts' => "$an <font style='font-size:12px;'>[$src_site] <a href='http://google.com".$link."'  target='news' style='font-size:17px;'>$title</a> $up_day");

  																																																																							$as++;

																																																													}  # end of foreach 002

												}   # end of foreach 001





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



# 구글

											echo "<html><body>";

											   echo $style_css;



											echo ("
															   <script type=\"text/javascript\">
															
																	
																	function calcHeight() {
																		var the_height =document.getElementById('res_frame').contentWindow.document.body.scrollHeight;
																		document.getElementById('res_frame').height = the_height;
																		document.getElementById('res_frame').style.overflow = \"hidden\";

															//   alert(the_height);

																	}


                 			
																	function hide_button(btn_type,btn_no) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기

                                                                        div_id_no=btn_type+'_'+btn_no;
																		//alert(div_id_no); 

																	  document.getElementById(div_id_no).style.display=\"none\";

                                                                             

                                                                      }


																</script>
												");

											echo "<table border=0 height=100% width=100%><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	";


                                           echo "<Tr valign=top><td width=$stock_thema_news_width>";  # 왼쪽 테이블


										                 echo "<table border=0 class=n1s>";


																						echo  "<tr valign=top><Td  colspan=$colspan_num>$link_php_list</td></tr>";

																						echo $dot_line;

																						echo  "<tr valign=top><Td  colspan=$colspan_num>  $thema_list_tags </td></tr>";
																						echo $dot_line;
																						echo  "<tr valign=top><Td  colspan=$colspan_num>  $stock_code_list_tags </td></tr>";

																						echo $dot_line;

																						#echo "<tr height=30><td></td></tr>";


																						echo "
																										<tr valign=top>

																													<Td width=600>
																													 <img src='../img/dot_r.gif'> $search_Tags $google_srch_Tags";

																													  foreach($thema_keyword_array as $value) echo "&nbsp; <img src='../img/up_arr.gif'> <a href='$cur_php?mode=news_read&key_word=".$value." ' style='color:red;'>$value</a>	&nbsp;";
																													
																								echo"					
																													</td>
																													<Td width=550><img src='../img/go_on.gif'> <font style='color:red;font-size:14px;font-weight:bold;'>$thema_story_title</font> 테마 관련 내용</td>
																													<Td width=230><img src='../img/go_on.gif'> 테마 관련 종목 </td>
																										</tr>
																									";


																						echo $dot_line;
																																												echo  "<tr valign=top><Td  colspan=$colspan_num>$srch_history_tags</td></tr>";
																						echo $dot_line;

																						echo "<tr valign=top><Td width=800 class=n1s>";

																						$an=1;


																						foreach( $Find_Link as $key_array) {
																							
																						   foreach( $key_array as $key_value) {	echo "<".$an."> ".$key_value."<br><br>"; $an++; }

																						}

																						echo "</td>";

																						echo "<td>$thema_story_tags</td>";

																						echo "<td>$thema_daily_stock_tags</td>";

																																												echo $dot_line;



																echo "</table>";

										echo "</td>";  # 왼쪽 테이블


   										    echo "<td  valign=top>";  # 오른쪽 테이블

																		echo "<table height=100% width=100%  ><tr>
																		<td><iframe src='' id='res_frame' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news1' ></iframe></td>

																		<td><iframe src=' ' id='res_frame' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news2' ></iframe></td>
																										
																		
																		</tr></table>";

										echo "</td>";  # 오른쪽 테이블


											echo "</tr></table>";


											echo "</body></html><iframe width=0 height=0 name=\"hiddenframe1\" style=\"display:none;\">";

											exit;

#################################################################
} # end of thema_news_read
#################################################################


#################################################################
function thema_file_attach ($connect) {
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

																																																						 

																																																							$thema_name_info = mysqli_fetch_array($result_thema_name);

																																																							  if($test_No==3)  echo $query_thema_name.$thema_name_info['thema_no']."<br>". empty($stock_info_array[8])."<br>";

																																																																																			  

																																																																														   if( !empty($stock_info_array[8]) && is_null($thema_name_info['thema_no']) ) {   # 기존에 등록된 테마 네임이 없고, 테마명이 있
																																																																																														
																																																																																																								$query_ins="insert into tbl_thema_name set thema_name='$stock_info_array[8]',uDate='$up_day[0]'";
																																																																																																   if(!$test_No) 	$result_ins=mysqli_query($connect,$query_ins); 

																																																																																																																									   ## 오류발생시
																																																																																																						   if(! $result_ins )  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! </font>$query_ins<br><br>"   ; 


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


																																																			## 오류발생시
																																																		   if(! $result_daily_ins )  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! </font><br><br>"   ; 




																																																			   #    if($test_No)
																																																					   echo "$ttn :: $query_daily_ins <br><br>";


																																					  #		echo $result_ins."<br>";

															 } # 테마 내용이 있는 경우에만


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






#################################################################
function thema_all_stock_attach ($connect) {
#################################################################
global $cur_php;


require "./env/inf.fnc";
#require "./env/fnc/sub_fnc/chg_css.php";
#require "./env/fnc/sub_fnc/auto_checkbox.php";


			 $query_all_stock="select * from all_stock_info limit 0,1";
			$result_all_stock=mysqli_query($connect,$query_all_stock); 


            $all_stock=mysqli_fetch_array($result_all_stock);




$today_ptime=calender_str(1,0,time());
$start_time=$today_ptime['unix_str'];


$krx_urls="http://data.krx.co.kr/contents/MDC/MDI/mdiLoader/index.cmd?menuId=MDC0201020101";

#  header('Content-Type: text/html; charset=utf-8');

  echo "<font color=white> $today" ;


			echo "	<html>
				  
					$style_css
					$calender_css

					$calender_js

					<BODY bgcolor=\"#232845\" leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" background='$bg_img[0]' style='font-size:15px;'>
					
					<table align=\"center\" border=0 cellspacing=\"5\" cellpadding=\"5\"  style='font-size:15px;' bgcolor=white width='600'>


                                   <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> krx  데이터 받기 ( 최종업데이트 시간 ::".$all_stock['uDate'].") </td></tr>

					                  <tr height=50><td><a href='$krx_urls' target='news2'>krx시세 다운로드</a> </td></tr>

					                                     <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> 전종목 주식 시세 업데이트 ( 가격,거래량,거래대금,전일비 ) </td></tr>

									   <tr height=50>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
												<input type='hidden' name=mode value='thema_all_stock_up'>
											  <input type='text' name='up_day'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform.start_time','','0');\" style='cursor:hand'>
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>


                                   <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> 전종목 주식 재무정보 업데이트 ( EPS,PER, BPS,PBR, 배당수익률 ) </td></tr>
									<tr>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>	
											<input type='hidden' name=mode value='thema_all_stock_up'>
											<input type='hidden' name=opt value='stock_info'>
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>
									
						</table>
					


					</body>
				</html>
				 ";


#################################################################
} # end of thema_all_stock_attach ($connect) {
#################################################################





#################################################################
function thema_all_stock_update ($upfile,$connect)  {
#################################################################
global $cur_php;
global $GR_Vals;





 if($GR_Vals['opt']!='stock_info') {  # 주식 재무정보 인 경우라면..

				 $query_del="delete from all_stock_info";
				$result_del=mysqli_query($connect,$query_del); 
 }		

$up_day=explode(" ",$GR_Vals['up_day']);




$file_open=fopen($_FILES['upfile']['tmp_name'],"r");
$file_Bytes=$_FILES['upfile']['size'];

$file_cts=fread($file_open,$file_Bytes);


 $file_cts = iconv("EUC-KR", "UTF-8", $file_cts);
 $file_cts=str_replace("\"","", $file_cts);
$file_line_cts = explode("\n", $file_cts);



		 foreach($file_line_cts as $key=>$all_stock_info) {

            if(empty($key)) continue;

		 $all_stock_value=explode(",",$all_stock_info);


     
	       if($GR_Vals['opt']=='stock_info') {  # 주식 재무정보 인 경우라면..

			      $query_str="update all_stock_info set  eps='".$all_stock_value[5]."',pre_eps='".$all_stock_value[7]."',per='".$all_stock_value[6]."',pre_per='".$all_stock_value[8]."',bps='".$all_stock_value[9]."',pbr='".$all_stock_value[10]."',div_rate='".$all_stock_value[12]."' where stock_code='".$all_stock_value[0]."'";

				  #print_r($all_stock_value);

				  $alert_msg="재무정보(per,pbr,배당률) 업데이트가 완료되었습니다.";

		   }

          else {

             $stock_vol=$all_stock_value[10];  ## 거래량(주)
			 $stock_vol_cap=$all_stock_value[11]/100000000; # 거래대금(억)
             $stock_cap=$all_stock_value[12]/100000000;  #   시총(억)

			 $query_str="insert into all_stock_info set stock_code='".$all_stock_value[0]."', stock_name='".$all_stock_value[1]."',stock_price='".$all_stock_value[4]."',stock_rate='".$all_stock_value[6]."',stock_vol='".$stock_vol."',stock_vol_cap='".$stock_vol_cap."',stock_cap='".$stock_cap."'  , uDate='".$up_day[0]."' ";

			# echo $query_str."<br>";

			 				  $alert_msg="전종목 시세 업데이트가 완료되었습니다.";

		  }
										
									
									$result_ins=mysqli_query($connect,$query_str); 
																
                                                          if(! $result_ins ) {  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! <br>$query_str<br>"   ;  

														                                     $err_code=1;
														  
														                                      }



		 }

#histroy.go(-1);
#<meta http-equiv=\"refresh\" content=\"1;url=$cur_php?mode=all_stock_attach\">
#exit;
#									<BODY onLoad='javascript:alert(1);histroy.back();' >			

if(!$err_code) {

									echo "
									<html>
									<body onload=\"alert('$alert_msg');window.history.back();\">
							
									</html>
										 ";

} else   echo " 오류가 발생했습니다.";

#################################################################
} # thema_all_stock_update ($adminID,$upfile,$connect)
#################################################################






#################################################################
function thema_list($connect) {
#################################################################
global $cur_php;
require "./env/inf.fnc";


$query_thema_list="select * from tbl_thema_name order by uDate desc";
$result_thema_list=mysqli_query($connect,$query_thema_list); 


			echo "	<html>
				  
					$style_css
					
					<BODY bgcolor=\"#232845\" leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" bgcolor=\"#999999\" bgproperties=\"FIXED\" background='$bg_img[0]' style='font-size:11px;'>
					
					<table align=\"center\" border=0 cellspacing=\"4\" cellpadding=\"0\"  style='font-size:17px;' bgcolor=white class=tt5>
					  
									   <tr>
											<td>테마이름</td>
											<td>Date</td>";


     while($thema_list=mysqli_fetch_array($result_thema_list)) {

           echo  "<tr><td>".$thema_list['thema_name']."</td><td>".$thema_list['uDate']."</td></tr>";

	 }






echo"
																			
									</tr>
									
						</table>
					


					</body>
				</html>
		 ";


#################################################################
} # end of thema_list($connect)
#################################################################






#################################################################
function news_scrap($connect) {
#################################################################
global $cur_php;
require "./env/inf.fnc";


$GR_Vals=Get_Vals('mode');

#print_r($GR_Vals);


																						$query_news="SELECT * FROM `tmp_news` where tmp_no =$GR_Vals[tmp_no]";
																						$result_news=mysqli_query($connect,$query_news); 

     																					$tmp_news=mysqli_fetch_array($result_news);

# print_r($tmp_news);

if($GR_Vals['opt']=='stock_code')  
	
	{ $query_str="stock_code='$tmp_news[stock_code]'";

	#$alert_title="종목 뉴스가 스크랩되었습니다.";

	}
else { $query_str="thema_no='$tmp_news[thema_no]'";

	#$alert_title="테마 뉴스가 스크랩되었습니다.";
	#alert(\"".$alert_title."\");
}

          $today = date("Y-m-d");

$news_title=addslashes ($tmp_news['news_title']);

$query_ins="insert into tbl_news_scrap set $query_str,news_title='$news_title',news_link='$tmp_news[news_link]' ,uDate='$today'  " ;
$result_ins=mysqli_query($connect,$query_ins); 

#echo $query_ins;

#exit;

echo"<BODY onLoad='javascript:self.close();' >";
#echo " window.onload = closeWindow(); ";

#################################################################
} # end of thema_list($connect)
#################################################################

?>