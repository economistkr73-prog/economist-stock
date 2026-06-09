<?php
require "./env/cnt.inc";
require "./env/inf.fnc";

require "./env/e.fnc";

# error 표시
 error_reporting( E_ALL );
# ini_set( "display_errors& ~E_NOTICE", 1 );

ini_set("allow_url_fopen",1);
include "shd/simple_html_dom.php";

$cur_php = basename($_SERVER['PHP_SELF']);


$GR_Vals=Get_Vals('');


#print_r($GR_Vals);

#  테마주 사이트, 티스토리 사이트


# 만약 키워드가 있다면..
if($GR_Vals['key_word'])	  $key_word=$GR_Vals['key_word']; 
else $key_word="테마주";

  $stock_talker_url="https://stockstalker.co.kr/?s=$key_word";

	










$List_Array[]=array(site_name=>"핀업의 주식이야기",read=>"https://finupstockstory.tistory.com/m",url=>"https://finupstockstory.tistory.com",fst_stR=>"ul.list_post",snd_stR=>"a",tit_stR=>"strong",up_stR=>"span.txt_date");
$List_Array[]=array(site_name=>"주식테마주 대장",read=>"https://msms7.tistory.com/m",url=>"https://msms7.tistory.com",fst_stR=>"ul.list_post",snd_stR=>"a",tit_stR=>"strong",up_stR=>"span.txt_date");
$List_Array[]=array(site_name=>"관련주 닷컴",read=>"https://related-stocks.tistory.com/m",url=>"https://related-stocks.tistory.com",fst_stR=>"ul.list_post",snd_stR=>"a",tit_stR=>"strong",up_stR=>"span.txt_date");

$List_Array[]=array(site_name=>"추세와 주도주",read=>"https://lovekase.tistory.com/m",url=>"https://lovekase.tistory.com",fst_stR=>"ul.list_post",snd_stR=>"a",tit_stR=>"strong",up_stR=>"span.txt_date");

$List_Array[]=array(site_name=>"Plan_B",read=>"https://no8888.tistory.com/m",url=>"https://no8888.tistory.com",fst_stR=>"ul.list_post",snd_stR=>"a",tit_stR=>"strong",up_stR=>"span.txt_date");


#$xml=file_get_html("https://finupstockstory.tistory.com/m");
#echo ($xml);
#exit;


	 for($x=0;$x<count($List_Array);$x++) {

                     $List_Xml_Array[$x]=Html_Crawling ($List_Array[$x]);
																							  
		 } # end of for




   $search_Tags ="			 <input type='text' name='key_word' value='테마주' size='14'  class=form_nc  onFocus=\"clearField(this)\" >						 
											 <input type=submit value=\"검색\"  class=form_nc style='cursor:hand'></form>";



echo "<html><body>";


echo $style_css;


echo ("
				   <script type=\"text/javascript\">
						function calcHeight() {
							var the_height =document.getElementById('res_frame').contentWindow.document.body.scrollHeight;
							document.getElementById('res_frame').height = the_height;
							document.getElementById('res_frame').style.overflow = \"hidden\";

				  //  alert(the_height);

						}
					</script>
	");

echo "<table height=100% width=100% border=0 ><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	<tr valign=top><Td>";

echo "<tr valign=top><Td width=560 >$search_Tags</td></tr>";


echo "<tr valign=top><Td width=560 >";


				foreach( $List_Xml_Array as $key_Array) {
					
										 $an=0;
												   foreach( $key_Array as $key_value) {	
																															  if($an>0) echo "<$an> ";

																															  echo "$key_value<br><br>";

																															   $an++; 																						  
																									 }


				}

echo "</td>";



if($mobile) {

}
else {
				echo "
				<td><iframe src='$stock_talker_url' id='res_frame' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news1' ></iframe></td>
				<td><iframe src=' ' id='res_frame2' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news2' ></iframe></td>
				<td><iframe src='' id='res_frame3' onload=\"calcHeight();\" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news3' ></iframe></td>";
			}


echo "</tr></table>";


echo "</body></html>";





# 2022년 10월 17일 월요일.  배열로 사이트 정보를 받아서,  원하는 정보를 배열형태로 넘김
############ start of Html_Crawling #######################
function Html_Crawling ($List_Array) { 
############ start of Html_Crawling #######################
global $mobile;



if($mobile) { $target_mobile1="mobile_main";
                  $mobile_font="font-size:24px";
}
else {
	$target_mobile1="news1";
	$target_mobile2="news2";
	$target_mobile3="news3";

	$mobile_font="font-size:14px";

}




$max_cnt=3;



								       $xml=file_get_html($List_Array['read']);

									#echo $xml;


									$Html_Crawling[0]="<font style='font-size:20px;color:red;'><a href='".$List_Array['read']."' target='$target_mobile3'>".$List_Array['site_name']."</a><a href='".$List_Array['url']."' target='".$target_mobile3."'><img src='../img/imoticon/num4/03.gif'></a></font>";



				
				foreach($xml->find($List_Array['fst_stR']) as $link_res) {

															 
															 
														#echo $link_res;

														foreach($link_res->find($List_Array['snd_stR']) as $res) {

																												   if(!empty($List_Array['tit_stR'])) $title=$res->find($List_Array['tit_stR'],0)->plaintext;

																												   	$title= shorten_Str($title,26,'..');



																												   $urls=$res->href;

																												   if(!empty($List_Array['up_stR'])) $up_day=$res->find($List_Array['up_stR'],0)->plaintext;
																												
																												
																												  $up_day_str=str_replace(".", "-", $up_day);																												   																								
																												  $up_time= calender_str(3,0,$up_day_str) ;
																												  $c_time=time();

																												  $new_articles="";
																												  $new_color="black";


																												  if( ($c_time-$up_time['mktime'])< (3600*24*2)) { # 2일 이내 등록

																												     $new_articles="<img src='../img/si_n.gif'>";
																												     $new_color="red;font-weight:bold;";

																												  }



																											  																												  
																												  if(  $mc<$max_cnt or ($c_time-$up_time['mktime'])< (3600*24*60) ) {		# 60일 이내에 등록된 것만.

																													     $url_link_str= $List_Array['url'].$urls;


																														   $Html_Crawling[]= "$new_articles <a href='$url_link_str' target='".$target_mobile1."' style='$mobile_font'>$title</a> <font style='font-size:13px;color:$new_color;'>$up_day</font> &nbsp;<a href='$url_link_str' target='".$target_mobile2."'><img src='../img/imoticon/num4/02.gif'></a> &nbsp;<a href='$url_link_str' target='".$target_mobile3."'><img src='../img/imoticon/num4/03.gif'></a>";

																														   $mc++;


																												                                                            }


																												   
															 }
																			 
																				  

                         }


#print_r($Html_Crawling);

return  $Html_Crawling;

############ end of of Html_Crawling #######################
}
############ end of of Html_Crawling #######################


exit;






?>