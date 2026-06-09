<?php
require_once "./env/cnt.inc";

# error 표시
 error_reporting( E_ALL  & ~E_NOTICE);
 ini_set( "display_errors", 1 );
 ini_set("allow_url_fopen",1);


#변수정의
$cur_php = basename($_SERVER['PHP_SELF']);
$admin_info=($_COOKIE['opt']);

// 1. 변수 안전하게 받기 (중복 선언 제거)
$mode = $_REQUEST["mode"] ?? ''; 

// ==========================================================
// 1. 라우팅 지도 (Mapping Table) : 모드명 => 실행할 함수명
// ==========================================================
$routes = [
    'search'                 => 'Pax_Search',
    'google_search'          => 'Google_Search',
    'Make_reaD'              => 'pax_Make',
    'Make_Update'            => 'Pax_Make_Update',
    'gt'                     => 'Pax_iFrame_suB',
    'if'                     => 'Pax_iFrame',
    'si'                     => 'stg_iFrame',
    'mf'                     => 'mobile_iFrame',
    'thema_manaGe'           => 'Thema_manaGe',
    'thema_writE'            => 'Pax_Thema_writE',

	'all_stock_attach'       => 'all_stock_attach',
    'all_stock_up'           => 'all_stock_update',  // pdo
    'all_etf_up'             => 'all_etf_update',   // pdo
    'all_etf_price_up'       => 'all_etf_price_update', //pdo
    'etf_list'               => 'etf_list', // pdo
    'stock_all'              => 'stock_all_history',
    'thema_all'              => 'thema_all',
    'thema_merge'            => 'Thema_merge',
    
    // 단축어(Alias) 설정
    'daily_news_scrap_list'  => 'daily_news_scrap_list',
    'dnsl'                   => 'daily_news_scrap_list', 
    'daily_news_scrap_write' => 'daily_news_scrap_writE',
    'daily_news_scrap_grp'   => 'daily_news_scrap_grp',
    'sal'                    => 'stock_analysis_list',
    'sal_write'              => 'stock_analysis_writE',
    'sal_view'               => 'stock_analysis_vieW',
    'sc_wr'                  => 'stock_cmt_writE',
    'daily_news_scrap_price_update' => 'daily_news_scrap_price_update',
    'dns_pu'                 => 'daily_news_scrap_price_update',
    'top_pi_ins'             => 'top_price_inserT',
    'top_pi_list'            => 'top_price_list',
    'top_pi_history'         => 'top_price_history',
    'top_pi_finish'          => 'top_price_finish',
    'top_pi_grp'             => 'top_price_grp',
    'top_pi_etc'             => 'top_price_etc_list',
    'vol_float'              => 'stock_vol_float_update',
    'vol_max'                => 'stock_max_vol_update',
    'ref_list'               => 'ref_lisT',
    'ref_update'             => 'ref_updatE',
    'realtime_pi'            => 'realtime_price_inserT',
    'stock_std_ins'          => 'stock_std_inserT',
    'stock_std_list'         => 'stock_std_list',
    'stock_std_cmt'          => 'stock_std_cmt',
    'stock_cmt'              => 'stock_cmt',
    'news_scrap'             => 'news_scrap',
    'news_scrap_list'        => 'news_scrap_list',
    'pop_url'                => 'pop_go_to_url',
    'fuf'                    => 'file_upload_form', 
    'file_upload_server'     => 'file_upload_server', 
    'dn_file'                => 'dn_file',
    'gtt'                    => 'get_ttt'
];


// ==========================================================
// 2. 실행 엔진
// ==========================================================

if (isset($routes[$mode]) && function_exists($routes[$mode])) {
    $func_name = $routes[$mode];

    // 1. 파라미터를 아예 받지 않는 함수들
    if (in_array($func_name, ['stg_iFrame', 'file_upload_form', 'file_upload_server'])) {
        $func_name();
    } 
    // 2. 최신 PDO 객체만 단독으로 사용하는 함수들
    else if (in_array($func_name, ['all_etf_update'])) {
        $func_name($pdo);
    }
    // 3. 🔥 [추가된 부분] 듀얼 커넥션(mysqli와 PDO 둘 다) 필요한 함수들!
    else if (in_array($func_name, ['all_stock_update','thema_all','etf_list','all_etf_price_update','top_price_list','top_price_inserT','top_price_grp'])) {
        $func_name($connect, $pdo);
    }
    // 4. 기본값: 나머지 기존 레거시 함수들 (mysqli의 $connect만 받는 경우)
    else {
        $func_name($connect);
    }

} else {
    echo "<meta http-equiv=\"refresh\" content=\"0;url=lo.php\">";
    exit;
}



############################################
function Pax_Search($connect) {
###########################################
global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');

if(empty($GR_Vals['key_word'])) $GR_Vals['key_word']="특징주";

$GR_Vals['key_word']=str_replace('\'','',$GR_Vals['key_word']); # 넘어온 키값에 ' 가 있다면 제거하고 db에 입력해라.



$key_word= strtoupper($GR_Vals['key_word']);
#$key_word= preg_replace("/[#\&\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $key_word); # /\

#echo $key_word;

if($key_word)
	
{  	

			   $srch_ins_array=array('opt'=> "insert",  'key_word'=> $GR_Vals['key_word'],'stock_code'=> $GR_Vals['stock_code']); # stock_code는 없는걸로

			    search_history($srch_ins_array,$connect);
}
 else $key_word="증시요약";



# 종목코드 찾기

            
					         $query_srch="select * from all_stock_info  where  stock_name='".$GR_Vals['key_word']."' ";
                             $result_srch=mysqli_query($connect,$query_srch); 

						    if($result_srch) { 
								                             $search_info = mysqli_fetch_array($result_srch);
								                             $get_stock_code=$search_info['stock_code'];
							}

					         $query_thema_srch="select * from tbl_thema_name  where  thema_name='".$GR_Vals['key_word']." '";
                             $result_thema_srch=mysqli_query($connect,$query_thema_srch); 

						    if($result_thema_srch) { 
								                             $search_thema_info = mysqli_fetch_array($result_thema_srch);															 
								                             $get_thema_no=$search_thema_info['thema_no'];
							}


$max_pages=$GR_Vals['max_pages'];
$G_thema_no=$GR_Vals['thema_no'];
$adminID=0;

#$query_del_news="delete from tmp_news where source='paxnet' ";
#$result_del_news=mysqli_query($connect,$query_del_news); 


$srch_get_array=array('urls'=>"$cur_php?mode=search&key_word=", 'limit_no'=> '3', 'double_target'=>'news_d2');
$srch_history_tags=search_history($srch_get_array,$connect);


  $search_tags="
                                <table class=tt4 border=0  width=100%>
								<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>								   
									<input type='hidden'  name=mode  value='search'>

								  <tr>
								   <td style='font-size:12px;'>	
								   <img src='../img/micon2.gif'>
								    <input type='text'  size='14'  name=key_word id='key_word' class=form_nc style='cursor:hand' value='".$key_word."' $auto_clear_tag   onkeyup=\"if(window.event.keyCode==13){form_go_to_url(document.myform)}\"></td>
									
							 ";

                                     ## 사전에 정의된 주요 키워드의 배열을 가져와서 태그로 만들어줌. 
									 

								   foreach($thema_keyword_array as $value)   $pre_defined_tags.="&nbsp; <img src='../img/up_arr.gif'> <a href=\"$cur_php?mode=search&key_word='".urlencode($value)."'\"  style='color:red;'>$value</a>	&nbsp;"; 

   
  $search_tags.="	  <td><table><tr><td>$srch_history_tags</td></tr></table>	
  
                                      </td>

										<td >
													<input type=button onclick=\"javascript:chkfrm('Make_reaD','news_d3');\"  style='cursor:hand;background:yellow;border:0;'  value='통합테마'>
									   </td>

									</tr></table>
								";


#변수정의

include "shd/simple_html_dom.php";

										$pax_url="http://www.paxnet.co.kr";

										$key_word= iconv("EUC-KR", "UTF-8", urlencode($key_word));
										$srch_urls="http://www.paxnet.co.kr/search/news?kwd=$key_word&wlog_nsise=search&order=1";
										#$srch_urls="https://www.google.com/search?q='특징주'&tbm=nws";



										#echo $srch_urls;

															$an=0;

										$get_html=file_get_html($srch_urls);										
										$find_List_TaG= $get_html->find('ul.thumb-list',0);	






							

																																																




										#var_dump($find_dd);

if(!empty($find_List_TaG)) {

	foreach($find_List_TaG->find('dl.text') as $art) {


													 $an++;

											$tit=$art->find('a',0)->plaintext;
											$short_tit= shorten_Str($tit,24,'..');

											$short_tit=str_replace( strtoupper($GR_Vals['key_word']),"<font style='color:red;font-weight:bold;font-size:20px;'>". strtoupper($GR_Vals['key_word'])."</font>",$short_tit);
											//$ori_title= addslashes($tit);

										 
											$url_links=$art->find('a',0)->href;

											$url_link_array=explode("=",$url_links); # [2] 아티클 숫자

											$url_link_str=$cur_php."?mode=reaD&no=".$url_link_array[2];

											$up_day=$art->find('dd.date',0)->plaintext;
										
											$up_day_array=explode(' ',$up_day);											
											$up_day_date=explode(".",$up_day_array[5]);
                                            $up_day_time=explode(":",$up_day_array[6]);

											
                                             #$up_mk_time= $up_day_array[5].$up_day_array[6];
                                             #$update_time = strtotime($up_mk_time);
                                             #$cur_time = strtotime("now");	

											      $mktime_str=mktime($up_day_time[0],$up_day_time[1],0,$up_day_date[1],$up_day_date[2],$up_day_date[0]);

                                                  $gap_min=intval((time()-$mktime_str)/60);

												  if($gap_min<(24*60)) {

													                                       if($gap_min<60) $up_day_str="<font style='color:red;font-size:15px;font-weight:bold;'>$gap_min 분전</font>($up_day_array[6])";
																							   else		$up_day_str="<font style='color:red;font-size:14px;'>".intval($gap_min/60)." 시간 전</font>($up_day_array[6])";
																					  } 
																					  
																					  else
																						{ 
																							   $gap_day=intval($gap_min/(24*60));														
																							   $up_day_str="$gap_day 일 전" ;														
																						}


											$link_url= "<tr height=33>
																			<td >
																						<input type=checkbox name='no[]' value='".$url_link_array[2]."'> <a href='".$pax_url."$url_links' target='news_d5' onclick=\"read_change_color('btn_color',".$an.");\"><span id='btn_color_$an'>$short_tit</span></a>  
																						<font style='font-size:11px;'> $up_day_str</font>
																						<a onclick=\"url_copy('".rawurlencode($tit)."','title');window.open('".$pax_url."$url_links','news','width=900, height=1900');\"><img src='../img/top_photo.gif' style='cursor:hand;'></a>
																					
																										
																						</td></tr>";

                                                     if($get_stock_code) $stock_name=$GR_Vals['key_word']; else $stock_name="";


											 #	$query_ins_news="insert into tmp_news set  thema_no='". $get_thema_no."', stock_code='". $get_stock_code."', stock_name='".$stock_name."',news_title='$ori_title',news_link='".$pax_url."$url_links',tmp_no=$an,source='paxnet' ";
											#	$result_ins_news=mysqli_query($connect,$query_ins_news); 

                                     			$List_Xml_Array[]=$link_url;



											# echo $an."<br>";

			}

}
										#print_r($List_Xml_Array);


										echo "<html><BODY >";

										echo $style_css;


										echo ("
														   <script type=\"text/javascript\">
																function      calcHeight() {
																	var the_height =document.getElementById('res_frame').contentWindow.document.body.scrollHeight;
																	document.getElementById('res_frame').height = the_height;
																	document.getElementById('res_frame').style.overflow = \"hidden\";

														  //  alert(the_height);

																}


  		                                       	function      toggle_button(toggle_id,toggle_txt) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기

																		
																	  	 const mode = document.getElementById(toggle_id);

																		  if(mode.style.display==\"none\") {
																			   
																										mode.style.display=\"block\";
																										document.getElementById(toggle_txt).innerHTML='';

																								}

																								else {
																														mode.style.display=\"none\";
																											           document.getElementById(toggle_txt).innerHTML='<img src=\"../img/plus.gif\">';

																												}

																		  //alert(ab);


																		 	//alert(toggle_id);

																		// mode.style.display=\"none\"; // 없애버림
																
																		 

                                                                      }




                                                                  function      read_change_color(btn_type,btn_no){


																	 id_no=btn_type+'_'+btn_no;


																	
																	 // id_no=btn_color+'_'+btn_no;

	  																      document.getElementById(id_no).style.color = '#D8D8D8';
																	// alert(id_no);

																	}



															function      chkfrm(mode_str,target_str) {	         

																		   f=document.myform;
																		   
                                                                         //   alert(f.mode.value);  // hidden으로 일단 만들어 놓은 상태에서 value값을 지정해줘야 함.

                                                                        f.mode.value= mode_str;  
																	  f.target=target_str;

																			f.submit();	

																			  go_to_url_tags  ='$cur_php?mode=dnsl';
																								
																			  window.open(go_to_url_tags, 'news_d4');

														
														                      }



						                                   		function      form_go_to_url(f) {  // 

																			  go_keys=f.key_word.value;		

																			
																																			//go_keys=decodeURIComponent(go_keys);  // 받은걸 decode
																																		
																																			  go_to_url_tags  ='$cur_php?mode=gt&type=d4&key_word=\''+go_keys+'\'';
																								
																																			  window.open(go_to_url_tags, 'news_d4');


																																			 //   go_to_urls_u5  ='https://new.infostock.co.kr/stockitem?code='+go_keys;         
																																			//				  window.open(go_to_urls_u5, 'news_d5');





																					}





																	function      hide_button(btn_type,btn_no) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기

																								div_id_no=btn_type+'_'+btn_no;

																								//alert(div_id_no); 

																							  document.getElementById(div_id_no).style.display=\"none\";

																				      }

			  
								
																		function      url_copy(urls,str){		
																																			var textarea_copy = document.createElement(\"textarea\");

																																	     	document.body.appendChild(textarea_copy);
																																		//	go_keys=decodeURIComponent(go_keys);
																																			textarea_copy.value = decodeURIComponent(urls);
																																			
																																			textarea_copy.select();
																																			document.execCommand(\"copy\");
																																			document.body.removeChild(textarea_copy);
																																			//alert(urls+\"이 복사되었습니다.\")

																																		//	window.clipboardData.setData('Text', 'hii');


																																		//frames['news_d2'].document.getElementById('news_title').value='1';		 // 추가한 종목 갯수				

																																
																																	//	document.body.key_word.value='1';
																																	//	alert(1);

																																	//	 get_srch_thema_keyword= prompt('복사합니다',str);

																																		//window.prompt(\"Enter\", str);

																																
																															}

															</script>
											");

$add_str=" 테마주 특징"; 

# " ,' 등 특수문자제거

#$key_word= preg_replace("/[#\+\-%@=\/\\\:;,\.'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $key_word); # /\ \&
#echo $key_word;

$g_key_word= $key_word.str_replace(' ',"+",$add_str);

$cur_time=time();


																																																																														
																																												   if(0) {
																																																				   $google_url ="https://www.google.com/search?q='".$key_word."'&tbm=nws&lr=lang_ko";																																																
																																																				
																																																					   #echo $google_url;
																																																					
																																																						$get_html_google=file_get_html($google_url);

																																																						#$txt=$get_html_google->find( 'div.search',0)->plaintext;
																																																						#$txtt = iconv("EUC-KR", "UTF-8", $txt);
																																																						#var_dump($txtt);

																																																						#$find_google=$get_html_google->find( 'div')->plaintext;		# 일단 크게 본 다음 소스 분석해야 함																																														 
																																																						#var_dump($find_google);
																																																					
																																																					   ## https 는 안됨.  뉴스와 전체인경우 구분해야 함. 어떻게?
																																																					   # 개발자 도구에서 봐야 함.  																																																					  

																																																					   echo "테스트시작=====================<br>";
																																																					  foreach($get_html_google->find( 'div.Gx5Zad.xpd.EtOod.pkphOe')  as  $gg_key=>$gg_value){

																																																						  echo $gg_key."<br> ";
																																																						  
																																																						  $ab_txt= iconv("EUC-KR", "UTF-8", $gg_value);
																																																						  $up_day=$gg_value->find('span.r0bn4c.rQMQod',0)->plaintext;
																																																						  
																																																						 # echo $ab_txt."<Br><br><br>";
																																																						  $pr_gg[]=$ab_txt;

																																																						  print_r($up_day);
																																																					  }
																																																					  echo "테스트 끝";

																																																					 # echo $pr_gg[1];

																																																					

																																																					  # div로 분류한후에.. 다시 서브 타이틀로 찾아야함

																																															   }
																																															

#if($max_pages==1 or $GR_Vals['key_word']=="특징주") 	$start_num_array=array(0,10);  # 폼input을 통한 검색(특징주)인 경우 최대 50개까지 보여줄것
#																			else 
																				
																			$start_num_array=array(0,10,20);

																			$as=1;

																		#	 $query_del_news="delete from tmp_news where source='google' ";
                                                                        #     $result_del_news=mysqli_query($connect,$query_del_news); 
																		

																		foreach($start_num_array as $start_num) { # start of foreach 001

																																					                                       $google_url ="https://www.google.com/search?q='".$key_word."'&tbm=nws&lr=lang_ko&start=".$start_num."";																																													
																																														   																																															
																																															$get_html_google=file_get_html($google_url);
																																															$div_google=$get_html_google->find( 'div'); # div.search 이걸로는 못 찾음.																														
																																																
																																						foreach($get_html_google->find( 'div.Gx5Zad.xpd.EtOod.pkphOe')  as  $link_res) { # start of foreach 002	
																																							
																																																   
																																																																						 $link=$link_res->find('a',0)->href;				

																																																																						   $src_site=$link_res->find('div.BNeawe.UPmit.AP7Wnd',0)->plaintext;
																																																																						   $title=$link_res->find('div.BNeawe.vvjwJb.AP7Wnd',0)->plaintext;

																																																																						    if(!$title) continue;

																																																																						   $ori_link ="https://google.com".$link;
																																																																						   
																																																																							$up_day=$link_res->find('span.r0bn4c.rQMQod',0)->plaintext;
																																																																						
																																																																						  # 구글에서 한글페이지만 검색하는 옵션 lr=lang_ko 을 선택했을때.. euc-kr로 리턴되는 듯. 이를 다시 utf-8로 변경

																																																																						  $title = iconv("EUC-KR", "UTF-8", $title);
																																																																						  $ori_title=$title;

																																																																						  $title= shorten_Str($title,23,'..');

																																																																						  $src_site = iconv("EUC-KR", "UTF-8", $src_site);
																																																																						  $up_day = iconv("EUC-KR", "UTF-8", $up_day);

																																																																						  
																																																																						   ### 업데이트 날짜가 2일 전, 1시간 전  이렇게 txt 형태로 되어 잇어 정렬이 어려움. 텍스트 제거후 일자에는 24을 곱해줘서 시간과 구분해줌.
																																																																							  if(strstr($up_day,"일 전")) 	  {
																																																																																						 $up_day_num=intval(substr($up_day,0,-7))*24*60; 
																																																																																						# $up_day=$up_day;
																																																																																					 
																																																																																					 }


																																																																									else if (strstr($up_day,"시간 전"))			{			$up_day_num=intval(substr($up_day,0,-7))*60;
																																																																									                                                                                 $up_time_str= calender_str(1,0,$cur_time-$up_day_num*60);
																																																																																													 $up_day="<font style='color:red;font-size:11px;'>$up_day(".$up_time_str['h'].":".$up_time_str['i'].")</font>";

																																																																																												  }

																																																																										else if (strstr($up_day,"분 전"))			{					 $up_day_num=intval(substr($up_day,0,-7));									
																																																																																															 $up_time_str= calender_str(1,0,$cur_time-$up_day_num*3600);																												
																																																																										 
																																																																																															   $up_day="<font style='color:red;font-weight:bold;;font-size:11px;'>$up_day(".$up_time_str['h'].":".$up_time_str['i'].")</font> <img src='../img/si_n.gif'> ";
																																																																																												  }

																																																																						#  종목코드 또는 테마코드가 있는 경우에 따로 따로 등록하는게 좋을듯..


																																																																						## 종목코드가 있으면  뉴스스크랩 버튼이 보일것

																																																																						

																																																																						
																																																																							$copy_title="<a onclick=\"url_copy('".rawurlencode($ori_title)."','title');window.open('".$ori_link."','get_news','width=900, height=1900')\"><img src='../img/top_photo.gif' style='cursor:hand;'></a><a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=2000&news_title=".rawurlencode($ori_title)."&&news_link=".rawurlencode($ori_link)."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>cc</a>";
																																																																							#url_copy('".rawurlencode($ori_link)."','title');

																																																																							
																																																																						#	$query_ins_news="insert into tmp_news set  stock_code='$get_stock_code',thema_no='$get_thema_no',news_title='$ori_title',news_link='$ori_link',tmp_no=$as,source='google' ";
                                                                                                                                                         																															#		$result_ins_news=mysqli_query($connect,$query_ins_news); 

																																																																							//echo "$scrap_stock_tag- $as - $url_key_word<br>";

																																																																																																																																							

																																																																							## 뉴스 목록을 보여줌



																																																																								$title=str_replace($GR_Vals['key_word'],"<font style='color:red;font-weight:bold;font-size:20px;'>".$GR_Vals['key_word']."</font>",$title);
																																																																								
																																																																							
																																																																							$Find_Link[$up_day_num][]="<a href='".$ori_link."'  style='font-size:12px;' target=_blank>[$src_site]</a> <a href='".$ori_link."'  style='font-size:17px;' target='news_d4' onclick=\"read_change_color(".$as.");\"><span id='btn_color_$as'>$title</span></a> $up_day&nbsp; $copy_title ";

																																																																					
  																																																																							$as++;

																																																													}  # end of foreach 002

												}   # end of foreach 001





										  if(!empty($Find_Link))	ksort($Find_Link);

											$colspan_num=4;




## 구글 검색

	echo "<table border=0  width=100% >";

																			echo "<tr><td >   $search_tags   </td></tr>";
																		echo "<tr><td > $pre_defined_tags																		  </td></tr>";



                                                                           echo "<Tr valign=top>
										                                                           <td width=".$tbl_width['i2t']." class=tt4 height=30>
																																   <table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:19px;font-weight:bold;background-color:yellow;' width=98%>
																																	   <tr valign=top><td>						
																																	   <img src='../img/micon2.gif'> 구글 검색결과 ::  <a href='https://www.google.com/search?q=".$key_word."&tbm=nws'  target=_blank>".$GR_Vals['key_word']."</a>
																																  </td></tr>

																																  </table>
																								   </td></tr>


																								   ";   	


                                                                                 echo "<tr valign=top><td><table valig=top  border=0>";

																										if(!empty($Find_Link)) {
																																							foreach( $Find_Link as $key_array) 																											
																																							   foreach( $key_array as $key_value) 	echo "<tr><Td height=33> ".$key_value."</td></tr>"; 

																										}  else  echo "<tr><Td height=33> 검색결과가 없습니다.</td></tr>"; 

                                                                                  echo "</table></td></tr>";

																					
											echo "</table>";



## 팍스넷 검색

									echo "<table height=100%  border=".$tbl_width['if_border'].">";

									
                                                                           echo "<Tr valign=top>
										                                                           <td width=".$tbl_width['i2t']." class=tt4 height=30>
																																   <table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:19px;font-weight:bold;background-color:yellow;' width=98%>
																																	   <tr valign=top><td>						
																																	   <img src='../img/micon2.gif'> 팍스넷 검색결과 ::  ".$GR_Vals['key_word']."</a>
																																  </td></tr>

																																  </table>
																								   </td></tr>


																								   ";   	

														echo "<tr valign=top>

																	<Td width=".$tbl_width['i1t'].">";													  
																	   
																	   echo "<table style='font-size:15px' width=100%>";

																
																				echo $dot_line;
																		

																		foreach( $List_Xml_Array as $key_Array) {

																				 echo $key_Array;

																		
																		}

																		echo "</form></table>";

							
										echo "</tr></table>";





										



                                                                                           if($GR_Vals['key_word']) {

																										   $arr_news_srch['qry']="select * from tbl_news_scrap  where  news_title LIKE '%".$GR_Vals['key_word']."%'  or rel_stock LIKE '%".$GR_Vals['key_word']."%'  order by no desc";																											
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$news_srch_array=php_mysql_Query($arr_news_srch,$connect);
																						   }


																											echo "
																											<table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:19px;font-weight:bold;background-color:#EFFBF2;' width=98%>
																																	   <tr valign=top><td>						
																																	   <img src='../img/micon2.gif'> 스크랩뉴스 :: 
																																  </td></tr>";		
																											
																											echo "<Tr><td>";



																											if($news_srch_array['value']) {
																																												 foreach($news_srch_array['value'] as $news_keys=> $news_value) {

																																														   $news_tags=get_scrap_news_tags($news_value,$GR_Vals['key_word']);

																																														   echo $news_tags;	
																																																																										   
																																													 }
																														}

																											echo "</td></tr>";


																											echo "</table>";



## news_scrap에서 찾기




																											



echo "</body></html>";



exit;


 ################### end of  Pax_search #######################
}
################### end of  Pax_search #######################


############################################
function Pax_iFrame() {
###########################################
global $cur_php;
global $tbl_width;
global $admin_info;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";
$GR_Vals=Get_Vals('mode');

get_Permit($admin_info,"$cur_php?mode=if");

#print_r($GR_Vals);

echo"<html><body width=100%>";

echo "

		   <script type=\"text/javascript\">
															

																 	function      toggle_button(toggle_id,toggle_txt) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기
																		
																	  	 const mode = document.getElementById(toggle_id);

																		  if(mode.style.display==\"none\") {

																										mode.style.display=\"block\";
																										document.getElementById(toggle_txt).innerHTML='';

																								}

																								else {
																														mode.style.display=\"none\";
																											           document.getElementById(toggle_txt).innerHTML='<img src=\"../img/plus.gif\">';

																												}

																		  //alert(ab);


																		 	//alert(toggle_id);

																		// mode.style.display=\"none\"; // 없애버림
																
																		 

                                                                      }



																function      calcHeight(frame_id) {
																	var the_height =document.getElementById(frame_id).contentWindow.document.body.scrollHeight;
																	document.getElementById(frame_id).height = the_height;
																	document.getElementById(frame_id).style.overflow = \"hidden\";

//alert(frame_id);
														         //    alert(the_height);


																}

																</script>

";


#calcHeight('res_frame_u3');\"


#

$tot_width=3710;
$left_width=2000;
$right_width=$tot_width-$left_width;


echo "

  	<table height='100%' width='100%' border=0 cellspacing=\"0\" cellpadding=\"0\">
   
						   <tr valign=top>
													<Td  height=".$tbl_width['top_height']." colspan=4>

													<table border=0 cellspacing=\"0\" cellpadding=\"0\">
													   <tr>

																					 <td width='".$left_width."px;'>
																
																								<iframe src='$cur_php?mode=gt&type=t1' id='res_frame_t1'    frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:".$tbl_width['top_height'].";\" name='news_t1' ></iframe>

																					</td>

																				<td    height=".$tbl_width['top_height']."  width=".$right_width."px;>
																									<iframe src='' id='res_frame_d3'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news_t3' ></iframe>
																				</td>
																				
																				

																</td>
															</tr>
														</table> 
										</td>

										 <td width=".$tbl_width['i5t']." valign=top rowspan=2>
																								<iframe src='$cur_php?mode=thema_all' id='res_frame_t5'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news_d5' ></iframe>
																				</td>

								</tr>

 
									 <tr valign=top>

										<td valign=top width=".$tbl_width['i1t'].">
												<iframe src='$cur_php?mode=search&key_word=".$GR_Vals['key_word']."' id='res_frame_d1' \" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news_d1' ></iframe>
										</td>

										  <td width=".$tbl_width['i2t'].">
															<iframe src='$cur_php?mode=dnsl' id='res_frame_d3'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news_d2' ></iframe>
											    
													</td>


												<td   width=".$tbl_width['i3t'].">
																	<iframe src='$cur_php?mode=top_pi_list&all=1' id='res_frame_d3'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news_d3' ></iframe>
												</td>

											

												 <td   width=".$tbl_width['i4t'].">
																	<iframe src='$cur_php?mode=stock_std_list' id='res_frame_d4'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='news_d4' ></iframe>
												</td>


									</tr>
							";

			echo "</table>";

										echo "</body></html>";


 ################### end of  Pax_iFrame() #######################
}
################### end of  Pax_iFrame() #######################



############################################
function stg_iFrame() {
###########################################
global $cur_php;
global $tbl_width;
global $admin_info;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";
$GR_Vals=Get_Vals('mode');

get_Permit($admin_info,"$cur_php?mode=if");

#print_r($GR_Vals);

echo"<html><body width=100%>";

echo "

		   <script type=\"text/javascript\">
															

																 	function      toggle_button(toggle_id,toggle_txt) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기
																		
																	  	 const mode = document.getElementById(toggle_id);

																		  if(mode.style.display==\"none\") {

																										mode.style.display=\"block\";
																										document.getElementById(toggle_txt).innerHTML='';

																								}

																								else {
																														mode.style.display=\"none\";
																											           document.getElementById(toggle_txt).innerHTML='<img src=\"../img/plus.gif\">';

																												}

																		  //alert(ab);


																		 	//alert(toggle_id);

																		// mode.style.display=\"none\"; // 없애버림
																
																		 

                                                                      }



																function      calcHeight(frame_id) {
																	var the_height =document.getElementById(frame_id).contentWindow.document.body.scrollHeight;
																	document.getElementById(frame_id).height = the_height;
																	document.getElementById(frame_id).style.overflow = \"hidden\";

																}

																</script>

";


#calcHeight('res_frame_u3');\"


#

$tot_width=3710;
$left_width=2000;
$right_width=$tot_width-$left_width;


echo "
  	<table height='100%' width='100%' border=0 cellspacing=\"0\" cellpadding=\"0\">
   
						   <tr valign=top>
										<Td width=1250px;>								
												<iframe src='$cur_php?mode=sal' id='res_frame_s1'    frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='s1' ></iframe>
										</td>

										<td valign=top width=1250px;>
												<iframe  id='res_frame_s2' \" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='s2' ></iframe>
										</td>

										  <td>
													<iframe src='' id='res_frame_s3'  frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100%;\" name='s3' ></iframe>											    
										</td>

									</tr>
							";

			echo "</table>";

										echo "</body></html>";


 ################### end of  Pax_iFrame() #######################
}
################### end of  Pax_iFrame() #######################



############################################
function mobile_iFrame() {
###########################################
global $cur_php;
global $tbl_width;
global $admin_info;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";
$GR_Vals=Get_Vals('mode');


get_Permit($admin_info,"$cur_php?mode=mf");


echo"<html><body width=100% border=1>";
															

echo "

  	<table height=100% width=100% border=0 >
   
						   <tr valign=top width=100%>
													<Td  height=10>

															$link_php_list

													</td>
						   </tr>
												 
									 <tr valign=top width=100%>

										<td valign=top width=100%>
												<iframe src='$cur_php?mode=dnsl&key_word=".$GR_Vals['key_word']."' id='res_frame_d1' \" frameborder=\"0\" scrolling=\"yes\" style=\"overflow-x:hidden; overflow:auto; width:100%; min-height:100vh;\" name='mobile_main' ></iframe>
										</td>


									</tr>
							";

			echo "</table>";


										echo "</body></html>";



 ################### end of  Pax_iFrame() #######################
}
################### end of  Pax_iFrame() #######################




#################################################################
function Pax_iFrame_suB($connect) {
#################################################################
global $admin_info;
global $cur_php;
global $tbl_width;
global $mobile;
require "./env/e.fnc";
require "./env/inf.fnc";

if($admin_info['usr_level']==1) {
 $news_target="news_d4";		
 $disp_admin=1;

} else   $news_target="news"; 

$GR_Vals=Get_Vals('mode');

if($mobile) $mobile_font_array=array('title'=> "font-size:20px;",'stock' => "font-size:17px;",'cmt'=>"font-size:14px;",'rel_stock'=>"font-size:20px;",);
else  $mobile_font_array=array('title'=> "font-size:15px;",'stock'=>"font-size:14px;",'rel_stock'=>"font-size:12px;",'cmt'=>"font-size:12px;");

#print_r($GR_Vals);

$key_word= strtoupper($GR_Vals['key_word']);
$strip_key_word=str_replace("'",'',$key_word);


                     # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name  order by uDate desc";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);

																											#print_r($thema_srch_array['keys']);

                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];


																											# 테마이름순으로 정렬																													
																											foreach( $all_thema_name as $thema_key => $thema_value) {
																															 
																																		  $thema_name[]=$thema_value['thema_name'];
																																		  $sort_get_value[]= $thema_value;

																													}

																												#	 print_r($thema_name);

																													array_multisort($thema_name, SORT_ASC, $all_thema_name);



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



    if($GR_Vals['type']=='t1') {

#		print_r($GR_Vals);

#		$tbl_top_width=$tbl_width['i1t'];



																									$gt_t2_form_tags="  <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
																																											<input type='hidden' name=mode value='gt'>
																																											<input type='hidden' name=type value='t1'>	
																																";

																									   
																									   $srch_key_word=$strip_key_word;

																											$tsi=0;
																											$mn=21;

																											#$find_vals=0;

																											#$ss="등록하기";

																					
																											 $gt_t1_tags=" <table style='font-size:11px;' width=100% border=0>
																																									<tr><td> $gt_t2_form_tags</td><td>

																																										<table  style='font-size:10px;'  id='stock_thema_tags' width=100% border=0>";


																																											$gt_t1_tags.="<tr>$dot_line</tr><tr><Td height=45px;> ";

																																																							$gt_t1_tags.="<span align=center>								 
																																																							                                  
																																																															   <img src='../img/dot_r.gif'><input type='text' size=17 name=key_word value='$srch_key_word' id='key_word'  class=form_nc $auto_clear_tag  onclick=\"submit_search_Confirm(document.myform);\" style='font-size:22px;color:red;font-weight:bold;height:45px;'></form>
																																																															   </form>																																																															  <font style='font-size:15px;'>".$link_php_list."</td></tr>
																																																														";
																																										
																											foreach ($all_thema_name as  $tsa_key => $thema_srch_info) {  

																																		   $mode_no=$tsi%$mn;
																																		   $thema_name= str_replace("$srch_key_word","<font style='color:red;font-weight:bold;font-size:20px;'>$srch_key_word</font>" ,$thema_srch_info['thema_name']);


																																		   if($mode_no==0) $gt_t1_tags.="<Tr><td height=27px;> ";

																																								$gt_t1_tags.=" 
																																								<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=00021&thema_no=".$thema_srch_info['thema_no']."&key_word=".$thema_srch_info['thema_name']."','pop_hidden','width=10, height=10');read_change_color('btn_srch_tsi',".$tsi.");put_insert_value('id_srch_key_word','".$thema_srch_info['thema_name']."','srch_thema_keyword','".$thema_srch_info['thema_name']."');\" style='cursor:hand;'>
																																								<span id='btn_srch_tsi_$tsi' style='color:#08088A;'>".$thema_name."<span></a> &nbsp; "; 

																																			if($mode_no==($mn-1)) $gt_t1_tags.="</td></Tr>";

																																			$tsi++;

																											}
																										 

																																																						
																																							

																											 $gt_t1_tags.="</table>";

} # 끝 테마 등록 및 검색



    if($GR_Vals['type']=='t3') {

#print_r($GR_Vals);


## 시작 뉴스 스크랩 리스트 가져오기

$tbl_top_width=$tbl_width['i3t'];

   $today = date("Y-m-d");

# 만약 news_title 이 있다면? 등록함

if($GR_Vals['news_title']) {   # start of if :: news_title

 # tbl_news_scrap
													   $arr_scrap_ins="insert into tbl_news_scrap set stock_code='".$GR_Vals['stock_code']."', thema_no='".$GR_Vals['thema_no']."',   news_title='".$GR_Vals['news_title']."',   news_link='".$GR_Vals['news_link']."'";													  
													   $result_ins=mysqli_query($connect,$arr_scrap_ins); 

													  # echo $arr_scrap_ins;

							}  # end of if :: news_title


## 이벤트 날짜가 있다면 등록할 것
if($GR_Vals['event_uDate']) {   # start of if :: event_uDate

	                                                      $event_uDate=explode(' ',$GR_Vals['event_uDate']);


													   $arr_scrap_ins="insert into tbl_event_Diary set stock_code='".$GR_Vals['stock_code']."', thema_no='".$GR_Vals['thema_no']."',   event_title='".$GR_Vals['event_title']."',  news_no='".$GR_Vals['news_no']."',  event_uDate='".$event_uDate[0]."'";													  

													   $result_ins=mysqli_query($connect,$arr_scrap_ins); 

													   #echo $arr_scrap_ins;

			}  # end of if :: event uDate


														 $arr_scrap['qry']="SELECT * FROM `tbl_news_scrap`  order by no desc limit 0,30";
														 $scrap_news=php_mysql_Query($arr_scrap,$connect);

														 foreach($scrap_news['value'] as $sn_key => $sn_value) { # start of foreach 종목과 테마 분리
															  															  

																     if(!empty($sn_value['stock_code']))   					 $scrap_stock_news[]=$sn_value;  
																	 elseif(!empty($sn_value['thema_no']))     $scrap_thema_news[]=$sn_value;


																 } # end of foreach 종목과 테마 분리


																							                     $scrap_thema_tags="<table style='font-size:12px;padding:10px;' border=0 width=98%>";  # tbl 001

																												 $scrap_thema_tags.="<tr><td > 
																																									<table  style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:12px;' width=98%>  ";
																																										 																								


																												   $scrap_thema_tags.="<tr><td> <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>$calender_js</td><td></td><td></td><td>
																												  
																												   <input type='hidden'  name=mode  value='gt'>
																												   <input type='hidden'  name=type  value='t3'>
																												   <input type=hidden name=stock_code value=".$GR_Vals['stock_code'].">
																												   <input type=hidden name=thema_no  id='thema_no_1'  value=''> 
																												   (이벤트)<input type='text' name='event_uDate'  id='start_time' value='$today' size='14' readonly class=form_nc onclick=\"check_mouse('myform2.start_time','','0');\" style='cursor:hand;text-align:center;'>
   																												   <input type='text' name='thema_name'   value='테마클릭' size='25'class=form_nc  $auto_clear_tag  id='thema_name_1'  onclick=\"javascript:openclub2('$cur_php?mode=thema_manaGe&opt=popup&type=pax&id_no=1','width=700,height=1200','get_thema')\" readonly style='cursor:hand;'>
																												   <input type='text' name=event_title  id='event_title' value='타이틀을 적어주세요' size='50'  class=form_nc $auto_clear_tag  onkeyup=\"if(window.event.keyCode==13){submit_Confirm(document.myform2,'event_title')}\"> 
																												   																												   <input type='text' name='news_no'  value='뉴스클릭' size='7'class=form_nc  $auto_clear_tag  id='news_no'  onclick=\"javascript:openclub2('$cur_php?mode=news_scrap_list','width=700,height=1200','get_news')\" readonly style='cursor:hand;'>
																												   </form></td></tr>";


																											       $scrap_thema_tags.="</table>";  # end of tbl 001-002

																												 
																												 $scrap_thema_tags.="</td></tr>";


                                                                                                                  $scrap_thema_tags.= "<tr><td>";

																										

																																		 			  $event_uDate_tags="  <table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;' width=98%>

																																				                                               	<tr>
																																														
																																																	<td>
																																																				<table  style='font-size:12px;'  id='scrap_stock_tags'>";


																																																				$ssn=0;


																																							 $arr_event_uDate['qry']="SELECT * FROM `tbl_event_Diary` where event_uDate >= '$today' order by event_uDate  limit 0,10";
																																							 $event_uDate_Array=php_mysql_Query($arr_event_uDate,$connect);


																																							 if($event_uDate_Array['value'] ) {																																							 

                           
																																																									 foreach($event_uDate_Array['value'] as $eun => $eun_value) {	

																																																																		 
																																																																   $up_eun_day=date_str(12,1,strtotime($eun_value['event_uDate'])); # 날짜를 스트링으로 표시

																																																																   if(!empty($eun_value['stock_code'])) $event_code=$all_stock_name[$eun_value['stock_code']]['stock_name'];
																																																																   if(!empty($eun_value['thema_no']))  $event_code=$all_thema_name[$eun_value['thema_no']]['thema_name'];


																																																																   ## 관련기사 가져오기

																																																																					 $arr_event_news['qry']="SELECT * FROM `tbl_news_scrap` where no='". $eun_value['news_no']."'  ";
																																																																					 $event_news_Array=php_mysql_Query($arr_event_news,$connect);


																																																																					 $get_news=$event_news_Array['value'][0];


																																																																					 if(!empty($get_news['news_link'])) $link_news="<a href='".$get_news['news_link']."' target='news_d5'><img src='../img/ic/12-em-check.png'></a>";
																																																																					 else $link_news="";

																																																															
																																																																	$event_uDate_tags.="<Tr><td width=40>".$up_eun_day['str']."</td> ";

																																																																	$event_uDate_tags.="<td  width=160>".$event_code."</td> ";

																																																																	$event_uDate_tags.="<td nowrap>".$eun_value['event_title']."<span> $link_news &nbsp; "; 

																																																																	$event_uDate_tags.="</td></Tr>";

																																																																			$ssn++;

																																																											}

																																																			 $event_uDate_tags.="</table></td></tr></table>";

																																							 }




	}  # end of if t3



    if($GR_Vals['type']=='d3') {


	}  # end of if d3




    if($GR_Vals['type']=='d4') {

## 시작 뉴스 스크랩 리스트 가져오기


#$stock_opt['type']=21;
#$stock_opt['str']="%";


$srch_get_array=array('urls'=>"$cur_php?mode=gt&type=d4&key_word=", 'limit_no'=> '14', 'double_target'=>'news_d2','opt'=>'stock');
$srch_history_tags=search_history($srch_get_array,$connect);



	echo "<table  width=100% border=0 style='font-size:19px;'>";


echo"<tr  bgcolor='#FBEFFB'> <td colspan=4>".$srch_history_tags."</td></tr>";



#print_r($GR_Vals);

												 #  종목코드가 있다면... 종목코드로 찾고,
												#	테마코드가 있다면.. 테마코드로 찾고..
												#	키워드만 있다면.. 
												#   그냥 찾고..

															
												  if($key_word) {

																										$arr_stock_name['qry']="SELECT stock_code,stock_name FROM `all_stock_info` where stock_name=".$key_word."";
																										$arr_stock_name['just_one']=1;
																										$find_stock_name=php_mysql_Query($arr_stock_name,$connect);
																										
																										if($find_stock_name['row']) {   $find_stock_code= $find_stock_name['value']['stock_code'];  }

																										else  {

																											$arr_thema_name['qry']="SELECT thema_name,thema_no  FROM `tbl_thema_name` where thema_name=".$key_word."";
																											$arr_thema_name['just_one']=1;
																											$find_thema_name=php_mysql_Query($arr_thema_name,$connect);
																											
																											if($find_thema_name['row']) {  $find_thema_no= $find_thema_name['value']['thema_no'];  
																											}
																											   
																										}
																					


																							# 쿼리문 만들기

																							  if($find_stock_code) {  $mkr_qry=" stock_code='$find_stock_code'";

																							  # 해당일 주식 코멘트 불러오기
                                                                                              $rel_stock_cmt=get_stock_cmt($find_stock_code,$srch_today,"stock",$connect);																						 

																																		}
																								
																																																 
																								$arr_find_stock['qry']="SELECT *  FROM `tbl_daily_thema_stock` where  ".$mkr_qry. "order by uDate desc limit 0,30";
																								$find_daily_thema_stock=php_mysql_Query($arr_find_stock,$connect);

                                                                                                     
																																						if(!empty($find_daily_thema_stock['value']) ) {  # start of if $find_daily_thema_stock

																																							   echo"<tr><td colspan=4 bgcolor='#FBEFFB'><a href='$cur_php?mode=stock_std_list&uDate=".$srch_today."' target='news_d4'>종목</a>  ::																																																									   
																																																									  </td></tr>" ;

																																																										echo "<tr>$dot_line</tr>";


																																																 foreach($find_daily_thema_stock['value'] as $mn=> $mn_value) {


																																																											 $query_news=" SELECT * FROM `tbl_news_scrap` WHERE no= '".$mn_value['rel_news_no']."' ";																																																												
																																																											 $result_news=  mysqli_query($connect, $query_news); 
																																																											 $chk_rel_news = mysqli_fetch_array($result_news);

																																																											 #중복된 뉴스가 있는지를 체크하면서.. 멀티배열로 바꿔줌
																																																											 if($chk_rel_news['rel_no']>0)  $base_no=$chk_rel_news['rel_no'];
																																																											 else  $base_no=$chk_rel_news['no'];

																																																											        $chk_news[$base_no][]=$chk_rel_news;										
																																																													$thema_stock_multi[$base_no][]=$mn_value;
																																																											 
																																																	 }  

																																																	 foreach( $thema_stock_multi as $tsm=> $tsm_value) { # start of foreach $thema_stock_multi 

																																																		 	echo "<tr><td width=".$tbl_width['sc_name_min']."  colspan=2 width=100%>";

																																																														   echo "  <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:14px;' width=100% border=0> ";


																																																		
																																																	  for($ts=0;$ts<count($tsm_value);$ts++) { 

																																																		                                             $fdts_value=$tsm_value[$ts];
																																																													 $rel_news=$chk_news[$tsm][$ts];

																																																													# print_r($fdts_value);

																																																													 #print_r($rel_news);

																																																													  $rel_stock_tags="";
																																																													   $rel_thema_tags="";
																																																													   $get_scrap_grp="";
																																																																									$thema_tags="";	
																																																																									$stock_cmt_tags="";
																																																																									$vip_img="";
																																																																									

																																																																									$stock_cmt_wr_img="<img src='../img/pen.gif' onclick=\"open_popUp_scwr('".$fdts_value['stock_code']."','".$fdts_value['no']."','".$fdts_value['uDate']."')\">";

																																																																									$rw=3;

																																																																						$key_str="<font style='font-size:17px;color:red;font-weight:bold;'>".$all_stock_name[$fdts_value['stock_code']]['stock_name'];

																																																																						 #$up_day2=date_str(3,1,strtotime($fdts_value['uDate'])); # 날짜를 스트링으로 표시

																																																																						  $up_day2=calender_str(3,13,$fdts_value['uDate']);	

																																																																									## 테마타이틀에 링크가 걸려 있으면 테크 할것
																																																																									
																																																																									$update_tags="<a href='$cur_php?mode=dnsl&uDate=".$fdts_value['uDate']."' target='news_d2'>".$up_day2['unix_str']."</font></a>";


																																																																									if($ts) 
																																																																												{
																																																																																	$rel_news_tit="";		
																																																																																	 $stock_info="";
																																																																																	 $rw=2;

																																																																												}

																																																																									else { 
																																																																											  $rel_news_tit="<tr>
																																																																		<td colspan=2><a href='".$rel_news['news_link']."' target='news_d5' style='font-size:20px;color:blue;'>".$rel_news['news_title']."</a></td></tR>";				 
																																																																											  $stock_info="(".cur_deco_txt($stock_opt,$fdts_value['stock_rate'],8,5,8).") 
																																																																																	(대금) ".deco_txt($fdts_value['stock_vol_cap'],133,500)."  억
																																																																																	(거래량) ".deco_txt($fdts_value['stock_vol']/10000,133,300)."  만주     
																																																																																	(시총)".deco_txt($fdts_value['stock_cap'],3,0)."  억 ";


																																																																									}

																																																																									# 그날의 종목 코멘트 가져오기
																																																																									
																																																																									$stock_cmt = $rel_stock_cmt[$fdts_value['uDate']];
																																																																									if($stock_cmt['vip']) $vip_img="<img src='../img/ic/16-heart-red-xs.png' title='유망종목'>";

																																																																									if(!empty($stock_cmt['stock_cmt'])) {	
																																																																										$stock_cmt_tags="<br><br><table  style='border: 1px dashed orange; border-radius: 7px; background-color:white; border-spacing:3px;font-size:12px;color:#2E64FE;height:30px;padding:3px;' width=160px;><tr><td>".nl2br($stock_cmt['stock_cmt'])."</td></tr>";

																																																																										if(!empty($stock_cmt['rel_url']))$stock_cmt_tags.="<tr><td><img src='../img/ic/16-file-page.png' valign=bottom> <a href='".$stock_cmt['rel_url']."' target='news_d5' style='color:red;'>".$stock_cmt['rel_title']."</tD></tr>";

																																																																										$stock_cmt_tags.="</table>";
																																																																										$stock_cmt_wr_img="";
																																																																									}



																																																																									# 뉴스에서 관련 종목이 있다면.. 표시할 것

																																																																									if($rel_news['rel_stock_info']) $rel_stock_tags=get_rel_stock_tags($all_stock_name[$fdts_value['stock_code']]['stock_name'],$rel_news['rel_stock_info'],$mobile,$disp_admin,$mobile_font_array);

																																																																									# 뉴스에서 관련 테마가 있다면 표시할것

																																																																									if($rel_news['rel_thema']) {

																																																																										  $rel_thema_array=explode('@@',$rel_news['rel_thema']);

																																																																																  for($r=0;$r<count($rel_thema_array);$r++) {		
																																																																																	  
																																																																																	  ###시작 : 뉴스번호를 가지고 이전 테마정보 가져오기

																																																																																	 $scrap_Vals=array('no'=>$rel_news['no'],'max_width'=>"490px",'disp'=>"scrap_news");
																																																																	                                                                 $get_scrap_grp=get_scrap_grp($scrap_Vals,$connect);

																																																																																	 
																																																																																	 $thema_tags="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10021&thema_no=".$rel_thema_array[$r]."&key_word=".$all_thema_name[$rel_thema_array[$r]]['thema_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>";
																																																																																
																																																																																					 $rel_thema_tags.= " $thema_tags".$all_thema_name[$rel_thema_array[$r]]['thema_name']."</a>&nbsp;" ;

																																																																																					  $mode_no=$r%3;
																																																																																					  
																																																																																					  if($mode_no==0 and $r>1) $rel_thema_tags.= "<br>";

																																																																																			 }
																																																																														 $rel_thema_tags=" &nbsp; <img src='../img/ico_thema.gif'> &nbsp; ".substr($rel_thema_tags,0,-1);

																																																																															} # 연관테마가 있다면.. 표시


																																																															
																																																															
																																																															if($ts==0)  { 


																																																																echo "<tr><td width='200px' height='30px;' rowspan=$rw align=center>$vip_img
																																																															<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_code=".$fdts__value['stock_code']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>
																																																																									$key_str</a></font> $stock_cmt_wr_img
																																																																									<br>																																																		
																																																																									".$update_tags.$stock_cmt_tags."																																																												
																																																															</td>
																																																															<td>".$stock_info."</td>
																																																															</tr>";																																																		
																																																															} 
																																																															else echo "<tr><td width='200px' height='30px;'align=center rowspan=$rw></td></tr> ";

																																																															echo $rel_news_tit;

																																																															echo "<tr><td>".$rel_stock_tags."</td></tr>";			 
																																																														
																																																															echo "<tr style='font-size:11px;line-height:20px;'><td></td><td style='color:#298A08;' >".$rel_thema_tags."</td></tr>";
																																																															echo "<tr style='font-size:11px;line-height:20px;'><td></td><td style='color:#298A08;' >".$get_scrap_grp."</td></tr>";																																																				
																																																															

																																																														# }

																																																	     } # end of for

																																																		 echo "</table>";



																																																															echo "</td></tr>";
																																																															
																																																															if($rel_news['rel_no']==0)   echo "<tr>$dot_line</tr>";

																																																	  } # end  of foreach $thema_stock_multi 


																								}  # end of if $find_daily_thema_stock


																																															  echo "</table>";




												  }

										

	}  # end of if u4






				echo "<html><body  leftmargin=0 topmargin=0 marginwidth=\"0\" marginheight=\"0\" >";

										echo $style_css;
										


										echo ("
														   <script type=\"text/javascript\">
															
																
																 	function     toggle_button(toggle_id,toggle_txt) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기

																		
																	  	 const mode = document.getElementById(toggle_id);

																		  if(mode.style.display==\"none\") {
																			   

																										mode.style.display=\"block\";
																										document.getElementById(toggle_txt).innerHTML='';

																								}

																								else {
																														mode.style.display=\"none\";
																											           document.getElementById(toggle_txt).innerHTML='<img src=\"../img/plus.gif\">';

																												}

												                                                        }



																	function     put_insert_value(id_name,key_name,id_key_str,id_key_value){

																		//alert(key_word);   테마이름과 테마번호를 넣어줌
	  																      document.getElementById(id_name).innerHTML = key_name;			
																		  
																		//  alert( id_key_value);

																	     document.getElementById(id_key_str).value = id_key_value;																

																	}



																function     hide_del_button(btn_name) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기
																		
																		//	document.getElementById(chk_str_id).value = 0;

																	  	 const mode = document.getElementsByName(btn_name);

																
																		    // document.getElementsByName 여러개 찾음 document.getElementById
																		    //  document.getElementById(div_id_no).style.display=\"none\";																		
																			//   	mode.style.visibility ='hidden';   // 숨기기
																			// mode.style.display=\"none\"; // 없애버림
																			// alert(mode.length);  //갯수를 찾기

																			//alert(mode[0].style.visibility);


																			for(var i=0;i<mode.length;i++){
																				      if(mode[i].style.visibility =='hidden') mode[i].style.visibility ='visible';
																					                                                                  else mode[i].style.visibility ='hidden';
                                                                               }

                                                                      }

																	  function     hide_all_del_button(btn_name) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기
																		
																	
																	  	 const mode = document.getElementsByName(btn_name);


																			for(var i=0;i<mode.length;i++)	        mode[i].style.visibility ='hidden';
                                                                               

                                                                      }


                                                                 function     read_change_color(btn_type,btn_no){

																	 id_no=btn_type+'_'+btn_no;

																
																	 // id_no=btn_color+'_'+btn_no;

	  																      document.getElementById(id_no).style.color = '#D8D8D8';
																	//alert(id_no);

																	}

					                                   																
																					
                                                            function     submit_Confirm(v,chk_str) {		
																
																//alert(chk_str);
																																 //  폼으로 넘어온 변수 이름과 값을 확인

																																 if(0) {
																																	 
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}

                                                                                                                     chk_vals=document.getElementById(chk_str).value;
																													


																										   if(chk_vals=='' || chk_vals=='타이틀을 적어주세요' ) { 
																											                                            alert('내용없음'); 
																										                                                return;
																																						}  // 제목이 없으면 등록 취소

																																					//alert(chk_vals);

																														

																										  if (confirm(\"등록하시겠습니까?\")) {
																											  v.submit();
																												
																											} 
																							}


														 function     submit_input_popUp(id_key_str) {

																																		 go_keys=document.getElementById(id_key_str).innerHTML;

																																			 var url ='$cur_php?mode=thema_writE&key_word=\''+go_keys+'\'          ';
																																			 var size ='width=550,height=1000'+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																			 var n=open(url,go_keys,size); 
																																			   n.focus(); 		
																									
																																	} // end of fnc ::: submit_input_popUp 


                               function     submit_search_Confirm(v) {


								   				 //  폼으로 넘어온 변수 이름과 값을 확인
																																	// for(loop = 0; loop < v.length; loop++) {
																																  //      alert(v[loop].name+ '==>' + v[loop].value);
																																	//					}

																																	   get_srch_thema_keyword= prompt('검색 테마이름');
																																		  v.key_word.value= get_srch_thema_keyword;
																																		   v.submit();

																																			 go_to_urls_d4  ='$cur_php?mode=thema_manaGe&key_word='+get_srch_thema_keyword;																								
																								
																																			  window.open(go_to_urls_d4, 'news_d4');																																			



																									} //   submit_search_Confirm


   						        function      open_popUp_scwr(stock_code,no,uDate) {

   																																		    var url ='$cur_php?mode=sc_wr&stock_code='+stock_code+'&rel_news_no='+no+'&uDate='+uDate+'          ';

																																			var popupX = (window.screen.width / 2) ;
																																			var popupY= (window.screen.height / 2) - (1000 / 2);
																																			 
																																			 var size ='width=520,height=450,left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																			
																																			 var n=open(url,'stock_pop',size); 

																																			   n.focus(); 		
																									
																																	} // end of fnc ::: 



															</script>
											");





echo "<table cellspacing=0   height=".$tbl_width['top_height']." border=0 width=100%>";


#	     <tr valign=top><td width=100%> $search_stock_name_tags </td></tr>
																													 
	#																												 <tr><td>$daily_thema_story_tags</td></tr>
																						



if($GR_Vals['type']=='t1') {
														
														echo " 	 <tr valign=top> <td width=100%>  $gt_t1_tags </td></tr> ";														
} 



elseif($GR_Vals['type']=='t3') {

echo "
									<tr  valign=top><td> $scrap_thema_tags </td></tr>

									<tr  valign=top><td> $scrap_stock_tags </td></tr>

									<tr  valign=top><td> $event_uDate_tags </td></tr>

									
															 ";




}


echo "
			</table>						
			";



		echo "								</body></html>";



exit;						

#################################################################
} # end of Pax_iFrame_suB
#################################################################

############################################
function get_rel_stock_tags($stock_name,$rel_stock_value,$mobile,$disp_admin,$font_array) {
###########################################

  $rel_stock_array=explode('@@',$rel_stock_value);



#		$rel_stock_tags="";

																																																														                      $rw=$rw+1;

																																																																			  for($r=0;$r<count($rel_stock_array);$r++) {
																																																																				               
																																																																				                  $rel_stock_info=explode('#',$rel_stock_array[$r]);
																																																																								  $stock_name[$rel_stock_info[1]]=$rel_stock_info[0];
																																																																								  																																																																								
																																																																								 if(!$mobile and $disp_admin) $infostock_open="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_code=".$rel_stock_info[1]."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>"; 
																																																																								 
																																																																								 else $infostock_open="<a href='https://new.infostock.co.kr/stockitem?code=".$rel_stock_info[1]."'>"; 
																																																																								  $opt_deco['type']=21;
																																																																								  $opt_deco['str']="%";
																																																																								  $rel_stock_info[0]= str_replace($stock_name,"<font style='font-size:17px;color:#610B38;font-weight:bold;'>$rel_stock_info[0]</font>",$rel_stock_info[0]);				            																																																																  
																																																																				                 $rel_stock_tags.= $infostock_open.$rel_stock_info[0]."</a>".cur_deco_txt($opt_deco,$rel_stock_info[2],8,5,8)." (".cur_deco_txt($opt_deco,$rel_stock_info[3],8,5,8).")  ";

																																																																								  $mode_no=$r%2;																																																																								  
																																																																								  if($mode_no==1 ) $rel_stock_tags.= "<br>";																																																																							 

																																																																						}

   	         $rel_stock_tags="<table  style='border: 1px dashed orange; border-radius: 7px; background-color:white; border-spacing:3px;height:30px;padding:3px;".$font_array['rel_stock']."' width=95% border=0><tr><td style='line-height:27px;color:gray;'>".substr($rel_stock_tags,0,-1)."</td></tr></table>";

 return $rel_stock_tags;


#################################################################
} # end of get_rel_stock_tags
#################################################################



############################################
function pax_Make($connect) {
###########################################
global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";
include "shd/simple_html_dom.php";  

$html = new simple_html_dom(); // Create a DOM object

$GR_Vals=Get_Vals('mode');

$G_No=$GR_Vals['no'];

#print_r($GR_Vals);
#변수정의

# 종목시세 업데이트 날짜 체크
  			$query_all_stock['qry']="select * from all_stock_info   limit 0,1 ";
			$query_all_stock['just_one']=1;
			$get_day_all_array=php_mysql_Query($query_all_stock,$connect);

			 $get_day_all_stock=$get_day_all_array['value']['uDate'];

	
             $today = date("Y-m-d");


if(!empty($GR_Vals['no'])) { 


			$query_del="delete from tbl_pax_daily_news where uDate='".$today."'";
			$result_del=mysqli_query($connect,$query_del); 

			#echo "$query_del";


			foreach ($GR_Vals['no'] as $key => $no) {  # start of foreach 001

				 			$query_ins="insert into tbl_pax_daily_news set thema_news_no='$no',uDate='$today'";
              				$result_ins=mysqli_query($connect,$query_ins); 

							#echo $query_ins;

							 $new_ins=1;

			}


} else {

  			$query_find="select * from tbl_pax_daily_news where uDate=(SELECT uDate FROM `tbl_pax_daily_news`  ORDER BY uDate DESC limit 0,1 )";
			$result_find=mysqli_query($connect,$query_find); 

						  
			 while($thema_news_info = mysqli_fetch_array($result_find)){

				# print_r($thema_news_info);

				 $G_No[]= $thema_news_info['thema_news_no'];

				 $up_day_rtime=$thema_news_info['uDate'];

				  $today=$up_day_rtime;


				  # 만약에.. 
				   $query_chk_find="select * from tbl_daily_thema_stock where uDate='$today'";
                   $result_chk_find=mysqli_query($connect,$query_chk_find); 
				   $check_j_code = mysqli_fetch_array($result_chk_find);

	
				   if(!($check_j_code)) $new_ins=1;
			 
				}


} # end of count


#echo $today;


if($get_day_all_stock < $up_day_rtime) $notice_str="<font style='color:red'><a href='$cur_php?mode=all_stock_attach' target='news_d4'>전종목 시세를 업데이트 해주세요. 바로가기</a></font>";
 else   $notice_str="시세업데이트 : ".$get_day_all_stock;


foreach ($G_No as $key => $no) {  # start of foreach 001


																																$urls="http://www.paxnet.co.kr/news/mainView?vNewsSetId=1445&articleId=$no"; #팍스넷 뉴스



																															#	echo $urls."<Br>";

																															   $get_html=connect_Http($urls,"GET",null);
																															  																															
																															 # echo $get_html;
																															  

																															$html->load($get_html['body']); 


																															 #$html= $get_html->find('body',0);

	                                                                                                                       #echo $html->find('tr');

																														   $gg=0;

																												foreach($html->find('tr') as $k2=>$link_res2) {		

																													if($k2==0)  continue; 

																															foreach($link_res2->find('td') as $k3=>$link_res3) {		

																																		$vals= $link_res3->plaintext;

																																		$parse_html[$key][$gg][$k3]=$vals;
																																		$gg++;
																															}
																								
																											}




}  # end of foreach 001

# print_r($parse_html);


    $gn=0;
	#$sort_stock_code[0]=0;

  foreach ($parse_html as $g1=>$g1_vals) {   # start of foreach 001



										   foreach ($g1_vals as $g2=>$g2_vals) {

																					 #   print_r($g2_vals);
																						
																					 $mode_no=$g2%3;

																					 if($mode_no==0)
																						 
																					   { 					
																						   $g2_code=explode("\n",$g2_vals[0]);

																						 #  print_r($g2_code);

																							 
																						   $stock_code=preg_replace("/[^0-9]*/s", "", $g2_code[1]);

																						   
																						  # $gn=$stock_code;


																						 $sort_stock_code[$gn]= $stock_code;

																						 $sort_key[$gn]= $sort_tags[$gn]['j_rate'];

                                                                                              if(!empty($stock_code)) {
																																		$exist_stock_code_keys=array_search($stock_code, $sort_stock_code) ; #  종목코드가 배열에 있다면, 키값을 돌려받음
																																		 if($gn!=$exist_stock_code_keys) $sort_tags[$exist_stock_code_keys]="";  # 기존꺼를 삭제함
																							  }
																						   																						
																						   $sort_tags[$gn]['j_name']=trim($g2_code[0]);
																						   $sort_tags[$gn]['j_code']=$stock_code;

																							 $special_pattern = "/[`~!@#$%^&*|\\\'\";:\/?^=^+_()<> ]/"; 											   
																							 $sort_tags[$gn]['j_rate']=preg_replace($special_pattern, "",$g2_code[3]);

																							 $sort_key[$gn]= $sort_tags[$gn]['j_rate'];

																					   }
																					 elseif($mode_no==1) $sort_tags[$gn]['j_title']=$g2_vals;
																					 elseif($mode_no==2) $sort_tags[$gn]['j_cts']=$g2_vals;

																					 if($mode_no==2)                                                 $gn++;
										   
										   }

 } # end of foreach 002

 #print_r( $sort_stock_code);
# print_r($sort_tags);

  array_multisort($sort_key, SORT_DESC, $sort_tags);

 #print_r($sort_tags);

  
    
$today_ptime=calender_str(1,0,time());
$today_rtime=$today_ptime['unix_str'];

#          $today = date("Y-m-d");

$update_tags="                 <span>
                                               <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
												<input type='hidden' name=mode value='Make_Update'>
											    <input type='text' name='uDate'  id='start_time' value='$today_rtime' size='14' readonly class=form_nc onclick=\"check_mouse('myform.start_time','','0');\" style='cursor:hand'>
												<input type=button value=\"등록\"  class=form_nc style='cursor:hand'  onclick=\"submit_Confirm(document.myform)\"> $notice_str  <a href='$cur_php?mode=dnsl' target='news_d4'>오늘의뉴스</a></span>

							";


##											   

      $pax_html_tags= "<table cellspacing=\"4\" cellpadding=\"4\"  border=0 >";

      $pax_html_tags.= "<tr><td ><input type=hidden id='open_num' value='0'></td></tr>\n\n";

     foreach ($sort_tags as $s1=>$s_vals) {   # start of foreach 001



                   #print_r($s_vals);

					if(empty($s_vals)) continue;



								 #가등록된 종목 있는지 체크해볼것
								                                     if($new_ins==0) {
																						   			$query_find="select * from tbl_daily_thema_stock where stock_code='".$s_vals['j_code']."'  and uDate='$today'";
			                                                                                        $result_find=mysqli_query($connect,$query_find); 
																									$check_j_code = mysqli_fetch_array($result_find);
																									#echo $query_find;

																									if(!$check_j_code['stock_code']) continue;

																	 }

                   # 종목뉴스 가져오기

				     $thema_stock_tags="";
 			         $thema_name="";
					 $thema_no="";
					 $j_title_tags="";
                     $j_cts_tags="";
					 $add_stock_name_tags="";


                      # 이름에 / 가 있다면?

					  if (strpos($s_vals['j_name'],'/')) { 

						    $find_code_array=explode('/',trim($s_vals['j_name']));

							 $qry_find['qry']="select stock_name,stock_code from all_stock_info where stock_name='".$find_code_array[0]."'";
							 $qry_find['just_one']=1;

							 # $qry_find['qry'];

							 $find_stock_code=php_mysql_Query($qry_find,$connect);

                           #  print_r($find_stock_code);

							 $s_vals['j_code']=$find_stock_code['value']['stock_code'];

										 for($sf=1;$sf<count($find_code_array);$sf++) {

											 $add_stock_name_tags.="<br>".$find_code_array[$sf];

										 }

					  }

		                     if(!empty($s_vals['j_code'])) {   # start of if s_vals

																												     	$query_thema_stock="SELECT tdts.uDate, tdts.thema_title, ttn.thema_no, ttn.thema_name FROM `tbl_daily_thema_stock` as tdts left join tbl_thema_name as ttn on tdts.thema_no=ttn.thema_no where tdts.stock_code='".$s_vals['j_code']."' order by uDate desc";
																														$result_thema_stock=mysqli_query($connect,$query_thema_stock); 

																														#   echo $query_thema_stock."\n\n";



                                                                                                                           # 테마명을 찾아서 배열에 넣음
																														   #".$thema_stock['thema_name']."
																															 while($thema_stock=mysqli_fetch_array($result_thema_stock)) {

																																							if($thema_stock['thema_no']) {
																																						   
																																											$thema_stock_tags.="<tr style='font-size:13px'   name='btn_".$s_vals['j_code']."'><td align=center style='font-weight:bold;'></td><td colspan=2>(".$thema_stock['uDate'].") ".nl2br($thema_stock['thema_story'])."</td></tr>";		

																																											#$thema_stock_name=$thema_stock['thema_name'];

																																										#	print_r($thema_stock);

																																											#echo $thema_stock['thema_name'];

																																											$thema_no=$thema_stock['thema_no'];
																																											$thema_name=$thema_stock['thema_name'];

																																											#print_r($thema_info);

																																										}

																															 }  # end of while
																																																												 
                                                                                                                           
																														   		
																															## 오늘 거래량,거래대금, 시가총액 가져오기

																																	 $query_all_stock="SELECT * FROM `all_stock_info` where stock_code='".$s_vals['j_code']."'";
																																	 $result_all_stock=mysqli_query($connect,$query_all_stock); 																																	 																																	 

																																	  if(! $result_all_stock )  echo "<font color=red>< Could not update data> $query_all_stock<br>"   ; 
																																	 while($all_stock=mysqli_fetch_array($result_all_stock)) 
														 {  $stock_info_str= " <span style='font-size:13px;'>거래량: ".deco_txt($all_stock['stock_vol']/10000,3,0)."만주  거래대금: ".deco_txt($all_stock['stock_vol_cap'],31,0)."억   시가총액 : ".deco_txt($all_stock['stock_cap'],3,0)."억    (회전율: ".deco_txt($all_stock['stock_vol_cap']/$all_stock['stock_cap']*100,132,50).") </span>";
																																	  $stock_name=$all_stock['stock_name'];
														 }

																															## 오늘 거래량,거래대금, 시가총액 가져오기

																															# 종목과 관련한 뉴스 스크랩 가져오기

																																																 $scrap_news_tags="<table style='font-size:14px;' ><tr><td colspan=3><img src='../img/c9.gif'> 스크랩뉴스</td></tr>";

																																																 if($thema_no>0) $thema_no_str=" or thema_no='".$thema_no."' ";
																																																 else $thema_no_str="";



																																																 $query_scrap['qry']="SELECT * FROM `tbl_news_scrap` where stock_code='".$s_vals['j_code']."'  $thema_no_str";

																																																# print_r($query_scrap['qry']);
																																																 #echo "$thema_no<Br>";

																																																 $result_scrap=php_mysql_Query($query_scrap,$connect); 			

																																										 	if(!empty($result_scrap['value'])) {

																																																  foreach($result_scrap['value'] as $s_key => $ssn_value) {

																																																		
																																																										  $up_stock_day=date_str(12,1,strtotime($ssn_value['uDate'])); # 날짜를 스트링으로 표시

																																																						
																																																											$scrap_news_tags.="<Tr><td width=10></td> ";

																																																											$scrap_news_tags.="<td nowrap><img src='../img/bul59.gif'> ".$up_stock_day['str']." ";

																																																											$scrap_news_tags.="<font style='font-size:13px;text-decoration: underline;text-underline-offset : 7px;text-decoration-style:dashed'><a href=' ".$ssn_value['news_link']."' target='news_d5' onclick=\"read_change_color('btn_srch_tsi',".$ssn.");\"><span id='btn_srch_tsi_$tsi' style='color:#08088A;'>".$ssn_value['news_title']."<span></a>"; 

																																																											$scrap_news_tags.="</td></Tr>";

																																																  }
																																																  	  $scrap_news_tags.="</table>";

																																											}  else $scrap_news_tags="";

																							 }  # end of if s_vals

																														$go_to_str=urlencode($stock_name);  ## 자바로 넘기기전 encode 할것

																														
																														   
																														   $infostock_open="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_code=".$s_vals['j_code']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>";
																														   
                                                                                                                       #    $arr_qry['qry']="SELECT stock_name FROM `tbl_stock_info` where stock_code='".$s_vals['j_code']."' ";
																														#   $stock_info=php_mysql_Query($arr_qry,$connect);
						 
						 
						               $pax_html_tags.="<tr  name='btn_".$s_vals['j_code']."' ><Td>
									   
									                                  <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;'> ";
						               $pax_html_tags.=" 
																			 <tr height='50px' style='background-color:#F1F8E0; '>
																				<td  width='".$tbl_width['i3t']."'  valign=middle>
																				<input type='hidden' name='chk_up[]' id='chk_up_".$s_vals['j_code']."' value='1' >

																				 &nbsp;  <a href=javascript:hide_button('".$s_vals['j_code']."');><img src='../img/tu.gif' alt='숨기기'></a>
																							$infostock_open".$stock_name."</a>  &nbsp;  <input type=checkbox name=vip[".$kk."] value='1' id=vip[]>     ".deco_txt($s_vals['j_rate'],13,0).$stock_info_str." &nbsp; $add_stock_name_tags
																				</td>
																				</tr>

																				";

																				$kk++;
					 
                        		 $pax_html_tags.= " <tr><td style='font-size:13px;line-height:190%;font-weight:bold;color:blue;font-size:17px; text-align:center' width='".$tbl_width['make_thema_cts']."' >";
						
																						   foreach($s_vals['j_title'] as $s_title)   $j_title_tags= "$s_title";
																				if($s_vals['j_cts'])		   foreach($s_vals['j_cts'] as $s_cts)   $j_cts_tags.= $s_cts;

						 $pax_html_tags.= "				 ".$j_title_tags."</td></tr>

						 <tr><td style='font-size:14px;padding:10px;line-height:30px;'>".nl2br($j_cts_tags)."</td></tr>";

		
												   
				$pax_html_tags.= "<tr><td><table>";
				
				$pax_html_tags.="<tr><td>
					<img src='../img/micon1.gif'> 연관주식 @</tD><td><input type='hidden' name='stock_code[]'  value='".$s_vals['j_code']."' ><input type='text' name='rel_stock[]'    id='rel_stock_".$s_vals['j_code']."' value='".$stock_name."' size='71'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
								</tD></tr>

								<tr><td><img src='../img/micon1.gif'> 연관테마 </td><td> <input type='hidden'  id='thema_no_".$s_vals['j_code']."' name=thema_no[] value='' > <input type='text'  id='thema_name_".$s_vals['j_code']."' name=rel_thema[] value='".$thema_name."' size='20'  class=form_nc readonly  onclick=javascript:openclub2('$cur_php?mode=thema_manaGe&opt=popup&type=pax&key_word=".$thema_name."&id_no=".$s_vals['j_code']."','width=700,height=1200','get_thema') style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'> 
								</td></tr>";

								$pax_html_tags.="<Tr><td><img src='../img/micon1.gif'>메인뉴스</td><td> <input type='text' name='news_title[]' id='news_title_".$n."' value='".$j_title_tags."' size='50'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>";

																																				$pax_html_tags.="	 <input type='text' name='news_link[]' id='news_link_".$n."' value='http://' $auto_clear_tag size='20'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'></td></tr>";

																																				$pax_html_tags.="<tr><td colspan=2><img src='../img/micon1.gif'> 관련 추가 뉴스 </td></tr>";



																																	 for($n=0;$n<2;$n++) {
																														
																																				$pax_html_tags.="<Tr><td><td> <input type='text' name='rel_news_title[".$s1."][]' id='rel_news_title_".$n."' value='".$rel_news_title[$n]."' size='50'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>";

																																				$pax_html_tags.="	 <input type='text' name='rel_news_url[".$s1."][]' id='rel_news_url_".$n."' value='http://' $auto_clear_tag size='20'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'></td></tr>";
																																		 }


								
								
								
						$pax_html_tags.="</table></td></tr>";



						 $pax_html_tags.= " <tr><td><table>$thema_stock_tags</table></td></tr>  





						 <tr><Td>$scrap_news_tags</td></tr>\n\n";

                            $pax_html_tags.="</Td></tr></table> ";

	 }  # end of foreach 001

      $pax_html_tags.= "<tr><td>$add_stock_tag </td></tr></table>";

# 


echo "<html><body onload='javascript:hidden_all();'  width=".$tbl_width['i3t']."  leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\">";


echo ("

				$style_css
					
				<script type=\"text/javascript\">


								 																																
										function     hide_button(btn_no) {  // 종목 이나 테마 뉴스를 등록하면 버튼이 사라지게 만들기

																											   btn_str_id= 'btn_'+btn_no;
																											   chk_str_id= 'chk_up_'+btn_no;
																												//alert(btn_str_id);

																												document.getElementById(chk_str_id).value = 0;

																											 const mode = document.getElementsByName(btn_str_id);
																									
																												// document.getElementsByName 여러개 찾음 document.getElementById
																												//  document.getElementById(div_id_no).style.display=\"none\";																		
																												//   	mode.style.visibility ='hidden';   // 숨기기
																												// mode.style.display=\"none\"; // 없애버림
																												// alert(mode.length);  //갯수를 찾기

																												for(var i=0;i<mode.length;i++){
																																mode[i].style.display=\"none\"; // 없애버림

																												  }

                                                                      }



										function     hidden_all () {  // 처음시작할때 다 감춰버림
					     															
																		    // document.getElementsByName 여러개 찾음 document.getElementById
																		    // document.getElementById(stock_all).style.display=\"none\";																		
																			//   document.getElementById('stock_all').visibility ='hidden';   // 숨기기
																			//   	mode.style.visibility ='hidden';   // 숨기기
																			// mode.style.display=\"none\"; // 없애버림
																			// alert(mode.length);  //갯수를 찾기

																			   

																			for(var i=1;i<3;i++){

																				            btn_str_id= 'hidden_stock_'+i;
																						
																						//alert(btn_str_id);
																				           const hidden_id = document.getElementById(btn_str_id);
																						//  alert(hidden_id);
																					
																						   hidden_id.style.display=\"none\"; // 없애버림
																						//hidden_id.style.visibility =\"hidden\";   // 숨기기	

																							
																							//alert(i);
                                                                                   }

																		 	 
                                                                      }


                                                            function     submit_Confirm(v) {

																										  if (confirm(\"등록하시겠습니까?\")) {

																											  v.submit();
																												
																											} else {
																												//alert('취소되었습니다.');
																											}


															}




														  function      xSize(e)		{
																														e.style.height = '1px';
																														e.style.height = (e.scrollHeight + 12) + 'px';

																										}

                                           function      add_stock_code() {

																																stock_code= prompt('종목코드');

																																//alert(stock_code);

																															  if(stock_code==0) {  alert('코드를 입력해주세요');

																																 return;

																																			}
																													 
																															   get_open_num=document.getElementById('open_num').value*1+1;															   															        

																																	   stock_id='stock_code_'+get_open_num;

																																	  // alert(stock_id);

																															  document.getElementById('open_num').value=get_open_num;		 // 추가한 종목 갯수													   															        

																															   document.getElementById(stock_id).value=stock_code;						// 종목코드를 숨겨서 입력									   															        

																															  document.getElementById('chk_up_'+get_open_num).value=1;    // 종목 내용 업데이트 할것

																																if(get_open_num>3)  alert('더이상 추가가 안됩니다');

																																	btn_str_id= 'hidden_stock_'+get_open_num;
																																   const hidden_id = document.getElementById(btn_str_id);

																																 hidden_id.style.height= \"300px\";
																																//	 hidden_id.style.visibility ='visible';   // 표시																	 

																																hidden_id.style.display=\"block\"; // 없애->보임


																																  //td_id=document.getElementById('stock_id')

																											}

												</script>



");

#window.location.href

#  <tr>$update_tags
#$calender_js</td></tr>";


echo "<table  width=\"100%\" border=0 cellspacing=0>";

echo "<tr><td>$update_tags</td></tr>";
            

echo     "<tr><td>";
echo      $pax_html_tags;



echo    "<span>$calender_js</span></td></tr>";
echo   "<tr><td>$pax_add_stock_tags</td></tr>";

echo "</form></table>";
echo  "</body></html>";


exit;



 ################### end of Pax_Make #######################
}
################### end of Pax_Make #######################



############################################
function Pax_Make_Update ($connect) {
###########################################
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');
$G_No=$GR_Vals['no'];

$test_on=0;

$up_day=explode(" ",$GR_Vals['uDate'])[0];

$up_day_str= $up_day." ".date("H:i:s");




if($test_on) { print_r($GR_Vals);}


#변수정의


  foreach($GR_Vals['chk_up'] as  $key=> $value) {

	          if($value) {

							$rel_news_link_tags="";

                           
											  foreach($GR_Vals['rel_news_title'][$key] as $rk=>$rk_value) {	       			     

												     	$news_tit=trim($rk_value);
														if($news_tit) $rel_news_link_tags.=addslashes($rk_value)."##^*^##".$GR_Vals['rel_news_url'][$key][$rk]."@@@@@@@";
													  }

													  $rel_news_link_tags=substr($rel_news_link_tags,0,-7);
							

									if($test_on) print_r( $rel_news_link_tags);

                       $up_Thema_Array[]= array('stock_code'=>$GR_Vals['stock_code'][$key],'thema_no'=>$GR_Vals['thema_no'][$key],'rel_thema'=>$GR_Vals['thema_no'][$key],'rel_stock'=>$GR_Vals['rel_stock'][$key],'news_title'=>addslashes($GR_Vals['news_title'][$key]),'news_link'=>$GR_Vals['news_link'][$key],'vip'=>$GR_Vals['vip'][$key],'rel_news_link'=>$rel_news_link_tags);

			  }
  }



#  print_r($up_Thema_Array);


   #   $query_del="delete from tbl_daily_thema_stock where uDate='".$up_day[0]."' ";
   #  if(empty($test_on)) $result_del=mysqli_query($connect, $query_del); 
     


 foreach($up_Thema_Array as  $tkey=> $thema_value) {

 #전체 정보 불러올것
                                                

	                                        $query_all_stock="select * from all_stock_info  where stock_code='".$thema_value['stock_code']."'";
											$result_all_stock_search=mysqli_query($connect, $query_all_stock); 
					  
										    $all_stock = mysqli_fetch_array($result_all_stock_search);

	
# 연관뉴스 합치기

 if($test_on) echo "$tkey  =======    $query_all_stock\n";
        
		
# stock_cap='".$all_stock['stock_cap']."', stock_vol='".$all_stock['stock_vol']."'  , stock_vol_cap='".$all_stock['stock_vol_cap']."' 


											$query_ins="insert into tbl_news_scrap set stock_code='".$thema_value['stock_code']."', thema_no='".$thema_value['thema_no']."', rel_thema='".$thema_value['rel_thema']."',rel_stock='".$thema_value['rel_stock']."',news_title='".$thema_value['news_title']."',news_link='".$thema_value['news_link']."', rel_news_link='".$thema_value['rel_news_link']."',vip='".$thema_value['vip']."',   uDate='".$up_day_str."',   rtime='".$up_day_str."'   ";
																
																					
											if(!empty(trim($thema_value['stock_code']))) { 

												if($test_on) { print_r($query_ins); echo "\n";}
												else $result_ins=mysqli_query($connect, $query_ins); 
                                     
											}

                                         													
                                                          if(! $result_ins ) {  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! <br>$query_ins<br>"   ;  

														                                     $err_code=1;
														  
														                                      }


                                              $all_stock="";
											  $thema_value="";

  }

	if($test_on) { echo "test 모드";
                               echo "$cur_php?mode=dns_pu&uDate=".$up_day."";

	}
	else { 
		#echo "테마종목 등록이 완료되었습니다.";

    echo "<body onload=location.href='$cur_php?mode=dns_pu&uDate=".$up_day."';>       ";

#		echo "	  <meta http-equiv=\"refresh\" content=\"0;url=> ";
	}


 ################### end of  Pax_Make_Update #######################
}
################### end of  Pax_Make_Update #######################



############################################
function Thema_merge($connect) {  ## 테마 합치기
###########################################

global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$test_on=0;

$GR_Vals=Get_Vals('mode');


if($test_on) print_r($GR_Vals);




if($GR_Vals['mode_two']=='merge') {

# tbl_thema_name 삭제
# tbl_daily_thema_stock     UPDATE `tbl_daily_thema_stock` set thema_no='173' WHERE thema_no='98'
# tbl_thema_story  UPDATE `tbl_daily_thema_story` set thema_no='173' WHERE thema_no='98'
# tbl_event_Diary
# tbl_news_scrap UPDATE `tbl_news_scrap` set thema_no='173' WHERE thema_no='98'


  $del_thema="delete from tbl_thema_name where no='".$GR_Vals['m_from']."'";
		 if($test_on)  echo $del_thema;
			 else { mysqli_query($connect,$del_thema); 			
			 }


    $up_tbl_array=array("tbl_daily_thema_stock","tbl_thema_story ","tbl_event_Diary","tbl_news_scrap");

		for($dta=0;$dta<count($up_tbl_array);$dta++) {
			$del_qry="update ".$up_tbl_array[$dta]."  set thema_no='".$GR_Vals['m_to']."' where thema_no='".$GR_Vals['m_from']."' " ;

		 if($test_on)  echo $del_qry;
			 else { mysqli_query($connect,$del_qry); 			
   			          table_auto_increment($del_tbl_array[$dta],$connect);  # 삭제후 번호를 마지막 번호로 셋팅
			 }
		}


	if(!$test_on) 				
	{


					echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=thema_all&no='".$GR_Vals['m_to']."'&key_word=".$GR_Vals['m_to_title']."#".$GR_Vals['m_to_title']."\";
													 self.close();
												</script>	 
										";		 
	}


exit;
}







                     # 시작 :전체 테마종목 가져오기
																	
                     # 끝 :전체 테마종목 가져오기




                     # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
                                                                                                          # 테마 네임 배열
																											#$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기



foreach( $thema_srch_array['multi_keys'] as $t_key => $t_value) {

	                  $thema_name[]=$t_value['thema_name'];

					}

array_multisort($thema_name, SORT_ASC,  $thema_srch_array['multi_keys']);
   
	       $thema_list_tags="<tr>";
             
#			 while($thema_info = mysqli_fetch_array($result_thema)){

foreach($thema_srch_array['multi_keys'] as $t_no => $thema_info){

				$cur_thema_style="";


			if($GR_Vals['no']==$thema_info['thema_no']) {

						  $cur_name=$thema_info['thema_name'];
						  $cur_thema_style="style='color:red;font-weight:bold;font-size:15px;'";
						  
					}

		            $nn=$tt%30;

		            if($nn==0)   $thema_list_tags.="<td valign=top>   <table border=0 class=n1s cellspacing=\"4\" cellpadding=\"4\" width=100%> <tr class=tt7><td width=200 colspan=2>테마이름</td></tr>";

		 		    $thema_list_tags.="<tr class=tt8 $cur_thema_style><td><a onclick=\"input_data('".$thema_info['thema_no']."','".$thema_info['thema_name']."');\" style='cursor:hand;'>".$thema_info['thema_name']."</td><td>".$thema_info['thema_no']."</td></tr>";

		            if($nn==29)   $thema_list_tags.="</table></td>";
		

                      $tt++;
				 
				}

				#add_thema_info('".$GR_Vals['id_no']."','".$thema_info['thema_no']."','".$thema_info['thema_name']."');

				    $thema_list_tags.="</tr>";

					echo "<html><body>";

echo $style_css;

echo 	"		<script type=\"text/javascript\">

    		 function     input_data(thema_no,thema_title) { // 종목코드를 받아서 넘김	
			 

																																													  document.getElementById('m_to').value=thema_no;
																																													  document.getElementById('m_to_title').value=thema_title;
																																												
																																													
																						}

           </script>
						
																						";


echo "<table  border=1 cellspacing=\"0\" cellpadding=\"0\" width=100%>";


echo " <tr><td colspan=10 style='font-size:15px;'>
	                                 <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
                               	   <input type='hidden'  name=mode  value='thema_merge'>
                               	   <input type='hidden'  name=mode_two  value='merge'>
								   From : ".$cur_name."<input type='hidden'  name=m_from  value='".$GR_Vals['no']."'>
									To: <input type='text'  name='m_to_title' id='m_to_title' class=form_nc size=20 >   
									<input type='text'  name=m_to  id='m_to' class=form_nc size=6 >                              
								<input type=submit value='등 록' class=form_nc style='width:40px;'></form>
								</td></tr>
								";



echo "<tr><td>".$cur_name."</td></tr>";

echo $thema_list_tags;



 ################### end of  Thema_merge #######################
}
################### end of  Thema_merge #######################





############################################
function Pax_Thema_writE($connect) {
###########################################
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');

$key_word=str_replace('\'','',$GR_Vals['key_word']);
$opt=$GR_Vals['opt'];

#print_r($GR_Vals);





  $find_str="< 테마스토리 신규 등록>";
  $opt_str="insert";

  $find_modify_str="테마 신규 등록";



####   테마 신규 등록 또는 수정,  신규 테마스토리 입력

  $today = date("Y-m-d");

 
# echo  $ins_thema_no;
 #echo "---------------<br><Br>";

  if($opt=='insert') { ##  테마를 신규 등록





																		                                                                        
																			  $query_ins="insert into tbl_thema_name set  thema_name='".$GR_Vals['thema_name']."',uDate='".$today."'   ";
																			  $result_ins=mysqli_query($connect,$query_ins); 		


																			  $query_srch['qry']="select * from  tbl_thema_name where thema_name='".$GR_Vals['thema_name']."'   ";
																			  $query_srch['just_one']=1;

																			  
																			  $result_srch=php_mysql_Query($query_srch,$connect); 	



																			 
																			  $ins_thema_no=	$result_srch	['value']['thema_no']	;		

																			  echo $ins_thema_no;
																			  
																			  
																			  $new_ins=1;
							  }

  elseif($opt=='update') { ##  테마 이름 수정																		
																		   
                                                                         $query_update="update tbl_thema_name set thema_name='".$GR_Vals['thema_name']."' where thema_no= '".$GR_Vals['thema_no']."' ";
													                     $result_update=mysqli_query($connect,$query_update); 

																		 #echo $query_update;

																		 $new_ins=1;

																		 $ins_thema_no=$GR_Vals['thema_no'];

												  }


 if($new_ins) {
	 												 echo "<body  onload='javascript:self.close();opener.location.reload();'>";

																exit;
							 }

###



                  

#exit;



				############  시작 :  검색어로 기존에  있던 테마이름 체크

									$query_thema['qry']="select * from tbl_thema_name  where thema_name like '%".$key_word."%'";

														#	echo $query_thema['qry'];

									$result_thema=php_mysql_Query($query_thema,$connect,); 


												  $thema_tags="<table border=0 class=n1s cellspacing=\"4\" cellpadding=\"4\">";
												  $thema_tags.="<tr class=tt4><td>테마이름</td><td>uDate</td></tr>";

 
                                             if($result_thema['value']) {

																		   foreach ($result_thema['value'] as $r_key => $r_value){

																									  if($r_value['thema_name']==$key_word) {																				 
																										  $find_thema_no= $r_value['thema_no'];

																										  $find_modify_str="테마 이름 수정";
																										  $opt_str="update";

																									  }  
																										 $r_value['thema_name']= str_replace("$key_word","<font style='color:red;font-weight:bold;font-size:20px;'>$key_word</font>" ,$r_value['thema_name']); 

																										$thema_tags.="<tr class=tt5><td>".$r_value['thema_name']."</td><td>".$r_value['uDate']."</td></tr>";

																			   }

											 }

															$thema_tags.="</table>";

				############  끝 :  검색어로 기존에  있던 테마이름 체크						   




										   $insert_daily_thema_tags=" 
										   <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;'> 
										   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>
																				<input type='hidden'  name=mode  value='thema_writE'>			
																				<input type='hidden'  name=thema_no  value='$find_thema_no'>																							
																				<input type='hidden'  name=opt  value='$opt_str'>																						<tr><td>
																				 																					

																				
																				<input type=text / name=thema_name value='".$key_word."' class=form_nc style='font-size:16pt;font-weight:bold;border:dashed 1px gray;border-radius: 10px;color:black;width:485px; height:40px;padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px; text-align:center;background:yellow;'>
																				 </td></tR>

																				<tr><td align=right >																				
																				  <input type='text' name='uDate'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform2.start_time','','0');\" style='cursor:hand;border-radius: 7px;border:dashed 1px gray'>
																				<input type=submit value='$find_str' style='width:180px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;'>
																				</td></tr>	<tr><td>$calender_js

																					<textarea name=thema_story style=\"width:485px; height:300px; overflow-x:hidden; overflow-y:auto;font-size:15px; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;border-radius: 10px;\" class=form_nc $auto_clear_tag ></textarea>
																					</td></tr>
																					
																			</form></td></tr></table>";






				echo "<html><body>$search_tags";

echo $style_css;



echo $thema_tags;

echo $insert_daily_thema_tags;




echo "</body></html>";

exit;
		



 ################### end of  Pax_Thema_writE() #######################
}
################### end of  Pax_Thema_writE()#######################




#################################################################
function news_scrap($connect) {
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');

#print_r($GR_Vals);


																						$query_news="SELECT * FROM `tmp_news` where tmp_no =".$GR_Vals['tmp_no']." and source='".$GR_Vals['source']."'";
																						$result_news=mysqli_query($connect,$query_news); 

     																					$tmp_news=mysqli_fetch_array($result_news);



 if(empty($tmp_news['stock_code']) and ($tmp_news['thema_no'])==0) $query_str="source='market_news',";



$rtime= date("Y-m-d H:i:s");

 $query_str.="stock_code='$tmp_news[stock_code]',thema_no='$tmp_news[thema_no]',rel_stock='$tmp_news[stock_name]', rel_utime='$rtime', rtime='$rtime'  ";


#        $today = date("Y-m-d");

$news_title=addslashes ($tmp_news['news_title']);

$query_ins="insert into tbl_news_scrap set $query_str,news_title='$news_title',news_link='$tmp_news[news_link]'  " ;
$result_ins=mysqli_query($connect,$query_ins); 

echo $query_ins;

exit;

echo"<BODY onLoad='javascript:self.close();' >";
#echo " window.onload = closeWindow(); ";

#################################################################
} # end of thema_list($connect)
#################################################################


#################################################################
function news_scrap_list($connect) {
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');

#print_r($GR_Vals);


																						$query_news['qry']=" SELECT * FROM `tbl_news_scrap`  order by no desc  limit 0,30 ";
																						$result_news=php_mysql_Query($query_news,$connect); 



echo "<html><body>";


echo ("
														   <script type=\"text/javascript\">

                                                     function     add_news_no(id_name,id_no) {

														 //alert(id_no);

											                          	self.close();

																	  opener.document.getElementById(id_name).value=id_no;
																		                                                                                                                                  

															}
																															
														

			</script>


											");


   
   
   echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' > "; # start of 1번째  tbl

																						foreach($result_news['value'] as $k_no => $k_value){


																						  echo "<tr><td></td><Td><a onclick=\"add_news_no('news_no','".$k_value['no']."')\" style='cursor:hand;'>".$k_value['news_title']."</a></td></tr>";


																						}

   echo  "</table> "; # start of 1번째  tbl
																					


echo "</body></html>";

exit;
echo"<BODY onLoad='javascript:self.close();' >";
#echo " window.onload = closeWindow(); ";

#################################################################
} # end of thema_list($connect)
#################################################################






#################################################################
function daily_news_scrap_list($connect) {  ##★★★★★★★★ 특징주 뉴스리스트
#################################################################
global $cur_php;
global $admin_info;
global $mobile;

$cur_year=date("Y");
$up_dir="./dta/news/$cur_year"; # 첨부파일을 업로드할 디렉토리 .. 년도별로 관리

require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');
$key_word=str_replace('\'','',$GR_Vals['key_word']);
#print_r($GR_Vals);

if($admin_info['usr_level']==1) {
 $news_target="news_d4";		
 $disp_admin=1;

} else   $news_target="news"; 


#$mobile=1;

if($mobile) $mobile_font_array=array('title'=> "font-size:20px;",'stock' => "font-size:17px;",'cmt'=>"font-size:14px;");
else  $mobile_font_array=array('title'=> "font-size:14px;",'stock'=>"font-size:12px;",'cmt'=>"font-size:12px;");

                    # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select * from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기



#print_r($admin_info);

if($GR_Vals['news_title']) {   # start of if :: news_title

 # tbl_news_scrap

                        if($GR_Vals['rel_thema']) $thema_no=explode('@@',$GR_Vals['rel_thema'])[0];

													   $arr_scrap_ins="insert into tbl_news_scrap set stock_code='".$GR_Vals['stock_code']."', thema_no='".$thema_no."',  news_title='".addslashes($GR_Vals['news_title'])."',   news_link='".$GR_Vals['news_link']."' ,   rel_stock='".$GR_Vals['rel_stock']."', rel_stock_catch_price='".$GR_Vals['rel_stock_catch_price']."',   rel_thema='".$GR_Vals['rel_thema']."'  ,    rtime='".$GR_Vals['rtime']."'  ,  rel_no='".$GR_Vals['rel_no']."'  ,     uDate='".$GR_Vals['rtime']."'   ";													  
													   $result_ins=mysqli_query($connect,$arr_scrap_ins); 


												if(0) {
																  echo $arr_scrap_ins;
																 exit;
												}

													 echo "	  <meta http-equiv=\"refresh\" content=\"0;url=$cur_php?mode=daily_news_scrap_price_update\"> ";

							}  # end of if :: news_title


# 가장최근 날짜를 가져와라

    $dta['qry']="SELECT DATE_FORMAT(uDate,'%Y-%m-%d') as uDate  FROM `tbl_news_scrap`  group by DATE_FORMAT(uDate,'%Y-%m-%d') desc  limit 0,6";																																													
	$dta['cur_day']=$GR_Vals['uDate'];
	$dta['urls']="$cur_php?mode=dnsl&uDate=";
	$dta['tbl']=array('width'=>"100px;",'height'=>"40px;");
    $get_date_list= get_date_List($dta,$connect);


# 가장최근 날짜를 가져와라


																						$query_news['qry']=" SELECT * FROM `tbl_news_scrap` WHERE DATE_FORMAT(uDate,'%Y-%m-%d')= '".$get_date_list['today']."' order by rtime desc ";
																						$result_news=php_mysql_Query($query_news,$connect); 

																						foreach($result_news['value'] as $k_no => $k_value){

                                                                                             $date_str=explode(' ',$k_value['uDate'])[0];

																							 #if($k_no==0) $recent_day=$date_str;

																							 if($k_value['rel_no']>0) $up_no=$k_value['rel_no'];
																							  else $up_no=$k_value['no'];
																							 

																							$daily_news_array[$up_no][]=$k_value;
																							
																						}

																						#print_r($daily_news_array);

																						
																																											
#  //document.getElementById('id_rtime').value=rtime_vals;																						


### 시작 : 종목매매내역 가져오기
 $qry_tr_stock="SELECT stock_code,profit,sell_cap,profit_rate from `tbl_trade_review`  where sell_Date='".$get_date_list['today']."' " ;
 $result_tr_stock=mysqli_query($connect,$qry_tr_stock); 

if($result_tr_stock) {

# 종목명,매매금액,수익,수익률
												foreach($result_tr_stock as $tr_no => $tr_value){
													 $stock_info=get_stock_info($tr_value['stock_code'],$connect);

													$trade_results[$tr_value['stock_code']]="<tr><td>".$stock_info['stock_name']."</td><td>".deco_txt($tr_value['sell_cap'],3,0)."</td><td>".deco_txt($tr_value['profit'],1,0)."</td><td>".deco_txt($tr_value['profit_rate'],13,0)."</td></tr>";
												}
							}

###  : 종목매매내역 가져오기

echo "<html><body width=".$tbl_width['make_thema_cts']." border=0>";


echo ("
													
														   
														   <script type=\"text/javascript\">

		
                                                            function      submit_Confirm(v,chk_str) {		
																
																
																																 //  폼으로 넘어온 변수 이름과 값을 확인

																																 if(0) {
																																	 
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}

                                                                                                                     chk_vals=document.getElementById(chk_str).value;


																													 
																												     stock_vals=document.getElementById('rel_stock_1').value;
																													 rtime_vals=document.getElementById('id_rtime').value;

																													 if(stock_vals=='종목명@') document.getElementById('rel_stock_1').value='';

																													// alert(document.getElementById('rel_stock').value);
																													// return;																												
																													
																											

																										   if(chk_vals=='' || chk_vals=='타이틀을 적어주세요'  ) { 
																											                                            alert('내용없음'); 
																										                                                return;
																																						}  // 제목이 없으면 등록 취소

																																					//alert(chk_vals);



                                                                                                          if(rtime_vals=='') {
                                                             
																																					real_time_str();

																													}

																									//	  if (confirm(\"등록하시겠습니까?\")) {
																											  v.submit();
																												
																								//			} 

																																																																	
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


	                               function      upload_file(up_dir) {
																							   
																								var Up_Dir = up_dir;
																								var popup_X = event.screenX;	
																								 var popup_Y = event.screenY;

																								 var urls='$cur_php?mode=fuf&dir_st='+Up_Dir+'';
																								 var zz;

																								 zz = window.open(urls, 'newpop', 'width=500, height=500,left='+popup_X+',top='+popup_Y);

																								  zz.focus();

											}


											   function      upload_file_inner_html(tit,urls,no) {

																							  // alert(tit);
																							 //  return;


																							   document.getElementById('news_title').value= tit;
																							   document.getElementById('news_link').value= urls;
														   
															
														}


							

										   function      open_popUp_scwr(stock_code,no,uDate,stock_cmt) {

									

   																																		    var url ='$cur_php?mode=sc_wr&stock_code='+stock_code+'&rel_news_no='+no+'&uDate='+uDate+'&stock_cmt='+stock_cmt+'          ';

																																			var popupX = (window.screen.width / 2) ;
																																			var popupY= (window.screen.height / 2) - (1000 / 2);
																																			 
																																			 var size ='width=520,height=450,left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																			
																																			 var n=open(url,'stock_pop',size); 

																																			   n.focus(); 		
																									
																																	} // end of fnc ::: 


			</script>





  $style_css


											");


$rtime_str= date("Y-m-d H:i:s");


echo "<table width=100%><tr><td>";



if($disp_admin) {

										echo "<table border=0  width=100%><tr><td>";

																																					echo     "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px;'  width=100%> "; # start of 1번째  tbl


																																					echo  "<tr>																																			

																																										<td> 
																																													<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																													<input type=hidden name=mode  value='daily_news_scrap_list'>
																																																		<img src='../img/micon1.gif'> <a href='$cur_php?mode=dnsl'>뉴스 스크랩</a> &nbsp; <input type='text' name=news_title  id='news_title' value='타이틀을 적어주세요' size='38'  class=form_nc $auto_clear_tag> urls <input type='text' name=news_link  id='news_link' value='https://' size='12'  class=form_nc $auto_clear_tag>
																																																		<input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_Confirm(document.myform,'news_title')\">

																																																		   &nbsp; <input type=button value=\"첨부\"  class=form2 style='cursor:hand'  onclick=\"upload_file('".$up_dir."')\">
																																																		   
																																									   </td>
																																								  </tr>";

																																					echo "<tr><td><img src='../img/micon1.gif'> 연관주식 <input type='text' name='rel_stock'    id='rel_stock_1' value='종목명@' size='59'  class=form_nc $auto_clear_tag style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																																					&nbsp;  <img src='../img/l2.gif'> <a href='$cur_php?mode=daily_news_scrap_price_update&uDate=".$recent_day."'>Price</a></td></tr>";
																																					echo "<tr><td><img src='../img/micon1.gif'> 연관테마 
																																											 <input type='text'   id='thema_name_1' value='' size='46'  class=form_nc readonly  onclick=javascript:openclub2('$cur_php?mode=thema_manaGe&opt=popup&key_word=".$thema_name."&id_no=1','width=950,height=1200','get_thema') style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																																											 <input type=hidden name='rel_thema' id='thema_no_1'  value=''>
																																											 <input type='text' name='rtime'  onclick=\"real_time_str('".$rtime_str."');\"  id='id_rtime' value='' size='18'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																																											 ";


																																					echo "</table></form>";
										echo "</td></tr><table>";
}

echo "</td></tr>";


echo "<Tr><td   style='border: 0px dashed orange; border-radius: 6px; background-color:#F8E0EC; border-spacing:3px;font-size:14px;' border=0 align=center>".$get_date_list['tags']."</td></tr>";


echo "<tr><td>";
      

																												 echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:14px;' border=0> "; # start of 1번째  tbl

																																																		foreach($daily_news_array as $k_m => $k_value_multi_array){

																																																			       # print_r($k_value_multi_array );			
																																																				   $kl++;
																																																				

																																																				    $base_day_str= calender_str(3,13,$k_value_multi_array[0]['uDate']);

																																																					 $end_mkt_time=mktime(15,20,0,$base_day_str['mm'],$base_day_str['dd'],$base_day_str['yyyy']);

																																																					 $start_mkt_time=mktime(9,0,0,$base_day_str['mm'],$base_day_str['dd'],$base_day_str['yyyy']);

																																																					 
																																																					  $wday=$base_day_str['w'];

																																																					  if($wday==0 || $wday==6) {
																																																						      $time_str= date("Y-m-d",$base_day_str['mktime']);
																																																						       $pu_tags="<a href=\"$cur_php?mode=dns_pu&uDate=".$time_str."\">";
																																																					  }

																																																						 if($kl==1) echo "<tr><td colspan=2 style='font-size:22px;font-weight:bold;text-align:center;' height=40px;>$pu_tags".$base_day_str['unix_str']."</a> </td></tr>
																																																				                               		 <tr><td colspan=2 style='font-size:22px;font-weight:bold;text-align:center;' height=40px;><a href='prj_yehior.php?mode=tdl&sell_Date=".$get_date_list['today']."' target='news_d5'>매매복기</td></tr>";																							
																																																						 

																																																						 $k_count=count($k_value_multi_array);

																																																						 $k_colspan=6;

																																																						 if($k_count>1) $k_colspan=$k_colspan+($k_count-1)*5;
																																																						 
																																																						   
																																																						       for($k=0;$k<$k_count;$k++){ # multi_array

																																																								            						  $k_value=$k_value_multi_array[$k];


																																																																	  $scrap_Vals=array('no'=>$k_value['no'],'max_width'=>"520px",'disp'=>"scrap_news");
																																																																	  $get_scrap_grp=get_scrap_grp($scrap_Vals,$connect);

																																																																	
																																																																	
																																																																								 $up_rtime[$k_key]=	strtotime($k_value['rtime']);

																																																																								 $new_key=$k_key-1;

																																																																								  if(  $k_key >0) {
																																																																									  
																																																																																	  if($start_mkt_time<$up_rtime[$new_key]  and  $start_mkt_time > $up_rtime[$k_key]  ) {

																																																																																						 $start_mkt_cmt="<tr ><td colspan=2 style='border: 0px dashed orange; border-radius: 6px; background-color:red; border-spacing:3px;font-size:14px;text-align:center;height:30px;color:yellow;'><장 시작></td></tr><tr><td height=15px></td></tr>";																			

																																																																																	 }  else $start_mkt_cmt="";

																																																																									  
																																																																																	  if($end_mkt_time<$up_rtime[$new_key]  and  $end_mkt_time > $up_rtime[$k_key]  ) {

																																																																																						 $end_mkt_cmt="<tr ><td colspan=2 style='border: 0px dashed orange; border-radius: 6px; background-color:blue; border-spacing:3px;font-size:14px;text-align:center;height:30px;color:yellow;'><장 마감> 이후 기사는 내일 시장에 반영될 수 있습니다.</td></tr><tr><td height=15px></td></tr>";
																																																																																						
																																																																																	 }  else $end_mkt_cmt="";

																																																																								  }


																																																																										 $rel_stock_tags="";
																																																																										 $rel_thema_tags="";
																																																																										 $stock_trade_tags="";
																																																																										 $trade_tags="";

																																																																																																																																									 
																																																																										if($k_value['stock_code']) {

																																																																														$Rank_Vals=array('rel_news_no'=>$k_value['no'],'dot_line'=>$dot_line,'ms'=>2,'font_size'=>'11px;');
																																																																														$rel_stock_tag=get_rel_stock_Rank($Rank_Vals,$connect);
																																																																														
																																																																											 $rel_stock_tags="<table width=100% border=0><tr><td style='line-height:27px;'>".$rel_stock_tag."</td></tr></table>";

																																																																											 if($trade_tags) $stock_trade_tags="<table  style='border: 1px dashed orange; border-radius: 7px; background-color:#FBF2EF; border-spacing:3px;font-size:12px;height:30px;padding:3px;' width=95%><tr><td style='font-size:11px;color:red;'>*당일 매매 내역</td></tr><tr><td style='line-height:27px;'>".$trade_tags."</td></tr></table>";

																																																																													} # 연관종목이 있다면.. 표시

																																																																													 if($k_value['rel_thema']) {
																																																																														  
																																																																														  $rel_thema_array=explode('@@',$k_value['rel_thema']);

																																																																														  for($r=0;$r<count($rel_thema_array);$r++) {	

																																																																															  

																																																																																	  if($disp_admin) $thema_tags="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10021&thema_no=".$all_thema_name[$rel_thema_array[$r]]['thema_no']."&key_word=".$all_thema_name[$rel_thema_array[$r]]['thema_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>";

																																																																																	  else $thema_tags="";		
																																																																																	  
																																																																																	  if($all_thema_name[$rel_thema_array[$r]]['finup_no']) $finup_no_tags="<a onclick=\"window.open('https://finance.finup.co.kr/Theme/".$all_thema_name[$rel_thema_array[$r]]['finup_no']."','thema_pop','width=1270, height=1900');\" style='cursor:hand;'>F</a>";
																																																																																	  else $finup_no_tags="";

																																																																																	  if(empty($get_scrap_grp))	 $scrap_grp_write="<a href=\"$cur_php?mode=daily_news_scrap_grp&mode_two=write&news_no='".$k_value['no']."' &thema_no='".$all_thema_name[$rel_thema_array[$r]]['thema_no']."' \"><img src='../img/c7.gif' ></a>&nbsp;";
																																																																																	  else  $scrap_grp_write="";

																																																																																			 $rel_thema_tags.= "$thema_tags".$all_thema_name[$rel_thema_array[$r]]['thema_name']."</a> ".$finup_no_tags." ".$scrap_grp_write ;


																																																																																			  $mode_no=$r%3;																		  
																																																																																			  if($mode_no==0 and $r>1) $rel_thema_tags.= "<br>";
																																																																																	 }

																																																																																	 if(!$k)  $rel_no_modify="<a href=\"$cur_php?mode=daily_news_scrap_write&no='".$k_value['no']."' &rel_no='".$k_value['no']."' \"><img src='../img/plus.gif' ></a> ";														
																																																																																	 else $rel_no_modify="";
																																																																											 
																																																																											 $rel_thema_tags="&nbsp;<a href=\"$cur_php?mode=daily_news_scrap_write&no='".$k_value['no']."' \"><img src='../img/ico_thema.gif'></a> &nbsp; ".substr($rel_thema_tags,0,-1).$rel_no_modify;

																																																																													} # 연관테마가 있다면.. 표시

																																																																										# 실제 뉴스 업데이트시간과 스크랩시간 차이

																																																																										 # $gap_minutes=intval((strtotime($k_value['uDate'])-strtotime($k_value['rtime']))/60);

																																																																										  if($gap_minutes>5) {  

																																																																													   if($gap_minutes>60*24) $chk_gab_str=intval($gap_minutes/60*24)."일 지연";
																																																																													   elseif($gap_minutes>60) $chk_gab_str=intval($gap_minutes/60)."시간 지연";																																																																		   
																																																																													   else   $chk_gab_str=$gap_minutes."분 지연";																																																																  
																																																																											  
																																																																													   $gap_minutes_tag= "<br><font style='font-size:10px;font-weight:bold;color:red;'>".$chk_gab_str."</font>";


																																																																										  }
																																																																										  else $gap_minutes_tag="";

																																																																										  $news_tit= shorten_Str($k_value['news_title'],50,'');

																																																																										  if($k_value['source']) { $mkt_img="<font style='".$mobile_font_array['cmt']." font-weight:bold;color:red;'>(시황)</font>";  $mkt_color="style='".$mobile_font_array['title']."color:#5858FA;'"; }
																																																																										  else  { $mkt_img=""; $mkt_color=""; }

																																																																										  if($k_value['top_pick']==1) { $tp_img="<img src='../img/tp01.png'>";   $tp_font="style='font-weight:bold;color:red;'";}
																																																																										  elseif($k_value['top_pick']==2) { $tp_img="<img src='../img/ext/spdf.gif'>";   $tp_font="";}

																																																																										  else { $tp_img=""; $tp_font=""; }



																																																																										  if($k_value['rel_news_link']) {

																																																																											  $rel_news_link_tags="<table style='border: 1px dashed gray; border-radius: 7px; border-spacing:3px;font-size:12px;padding:5px;width:90%'  ><tr><td colspan=2> <img src='../img/star.gif'> 관련 뉴스</td></tr>";
																																																																											  
																																																																											  $rel_news_link_array=explode('@@@@@@@',$k_value['rel_news_link']);

																																																																											  foreach($rel_news_link_array as $rnl_key => $rnl_value) {

																																																																												   $rel_news_link= explode('##^*^##',$rnl_value);

																																																																												    $stock_info=get_stock_info($rel_news_link[2],$connect);

																																																																												   if($rel_news_link[2])   $news_infostock_open=" [<a href='https://new.infostock.co.kr/stockitem?code=".$rel_news_link[2]."' target='news_d5' style='color:red;'>".$stock_info['stock_name']."</a>]"; 
																																																																												   else $news_infostock_open="";

																																																																												   $sc_wr_tag= " <img src='../img/pen.gif' style='cursor:hand'  onclick=\"open_popUp_scwr('".$rel_news_link[2]."','".$k_value['no']."','".explode(' ',$k_value['uDate'])[0]."','".str_replace("\"","&quot;",$rel_news_link[0])."')\">";

																																																																												   $rel_news_tit= shorten_Str($rel_news_link[0],40,'..');

																																																																												   $rel_news_link_tags.="<tr><td>&nbsp; </td><td><img src='../img/u2.gif'>$news_infostock_open  <a href='". $rel_news_link[1]."' target='news_d5'> ".$rel_news_tit."</a> ".$sc_wr_tag."</td></tr>";
																																																																											  }

																																																																											  $rel_news_link_tags.="</table>";


																																																																										  }  else $rel_news_link_tags="";

																																																																										  if($disp_admin) $modify_tags="<a href=\"$cur_php?mode=daily_news_scrap_write&no='".$k_value['no']."' \">";


																																																																										  echo  $start_mkt_cmt;   # 장개시전 멘트
																																																																										  echo  $end_mkt_cmt;   # 장마감 멘트

																																																																										    if($GR_Vals['no']==$k_value['no']) $find_title_style="font-weight:bold;background-color:yellow;";
																																																																											else  $find_title_style="";

																																																																					  
																																																																					  if($k==0) echo "<tr >
																																																																														<td  rowspan=$k_colspan style='font-size:11px;' valign=top align=center>$modify_tags".explode(' ',$k_value['rtime'])[1]."</td><Td  width=580px; style='$find_title_style".$mobile_font_array['title']."'>$tp_img $mkt_img  <a href='".$k_value['news_link']."' target=$news_target  $mkt_color $tp_font> ".$news_tit."</a>
																																																																														</td>
																																																																													</tr>";		

																																																																					  echo "<tr style='font-size:11;px;line-height:20px;'><td>".$rel_stock_tags."</td></tr>";
																																																																					  echo "<tr style='font-size:11px;line-height:20px;'><td style='color:#298A08;' >".$rel_thema_tags."</td></tr>";

																																																																					   echo "<tr style='font-size:11;px;line-height:20px;'><td>".$get_scrap_grp."</td></tr>";

																																																																					     if($k_value['rel_news_link'])  echo "<tr style='font-size:11px;line-height:20px;'><td style='color:#5F4C0B;'> ".$rel_news_link_tags."</td></tr>";
																																																																					   else echo "<tr style='font-size:11px;line-height:5px;'><td style='color:#5F4C0B;'></td></tr>";
																																																																					  
																																																																					  echo "<tr style='font-size:11;px;line-height:20px;'><td>".$stock_trade_tags."</td></tr>";

																																																																					  if($k_value['news_cmt']) 	  echo "<tr><td ></td><td><table  style='".$mobile_font_array['cmt'].";line-height:30px;width:88%; border-radius: 7px; background-color:#F8EFFB;'><tr><td style='color:#5F4C0B;'  valign=top><img src='../img/quote_left.png'></td><td align=left> ".$k_value['news_cmt']."  &nbsp; <img src='../img/quote_right.png'></td><td></td></tr></table></td></tr>";
																																																																					  
																																																																					  if($k==0 and $k_count==1) echo "<tr><td  height=4 style='font-size:8px;'></td></tr>";
																																																															
																																																							   } # end of multi_array

																																																				$dd++;

																																																			

																																																		}

																												   echo  "</table> "; # start of 1번째  tbl
																					


echo "</td></tr></table>";



echo "</body></html>";

exit;
#echo"<BODY onLoad='javascript:self.close();' >";
#echo " window.onload = closeWindow(); ";

#################################################################
} # end of thema_list($connect)
#################################################################

#################################################################
function top_price_list($connect,$pdo) {  #★★★★★★★★ 당일 상승률 상위 100 종목
#################################################################
global $admin_info;
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;
$GR_Vals=Get_Vals('mode');
$max_colspan=10;


// 1. class 관리자 소환
$stockRepo = new StockRepository($pdo);  //주식관련 데이타



$target_key='top_pi';                                          
$last_etf_update=data_upTime($target_key, 'call',$pdo);




if(0) {
    $up_Time_Tags="<table style='font-size:10px;'><tr>";
    foreach($up_Time_Array as $up_no => $up_value){
        $no++;
        $mode_no=$no%17;
        $up_day=explode(' ',$up_value['up_Time']);
        $up_hour=explode(':',$up_day[1]);
        $up_Time_Tags.="<td>($no)".$up_hour[0].":".$up_hour[1]."</td>";

        if($row_cat==$no) {  
            $up_day_date=explode('-',$up_day[0]);       
            $mktime_str=mktime($up_hour[0],$up_hour[1],0,$up_day_date[1],$up_day_date[2],$up_day_date[0]);
            $gap_min=intval((time()-$mktime_str)/60);
            if($gap_min>5) $up_Time_Tags.="<td style='color:red;font-weight:bold;font-size:14px;' colspan=2>~$gap_min 분 경과</td>";
        }
        if($mode_no==0) $up_Time_Tags.="</tr><tr>";
    }
    $up_Time_Tags.="</tr></table>";
} 

$target_key='top_uDate';                                          
$chk_finish=data_upTime($target_key, 'call',$pdo);

#신규종목 등록,  검색 기준
$std_cookie=($_COOKIE['std_list'] ?? []);
if($GR_Vals['limit_vals'])    { setcookie('std_list[limit_vals]',$GR_Vals['limit_vals'],time()+12800,'/');  $std_cookie['limit_vals']=$GR_Vals['limit_vals']; }
if(empty($std_cookie['limit_vals'])) $std_cookie['limit_vals']=5;

# 시작 :전체 테마종목 가져오기 (PDO 최적화 완료)
$sql_thema_init = "SELECT CAST(thema_no AS CHAR) AS t_key, CAST(thema_no AS CHAR) AS thema_no, thema_name FROM tbl_thema_name";
$all_thema_name = $pdo->query($sql_thema_init)->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

## 기본 변수
if(empty($GR_Vals['mode'])) $GR_Vals['mode']='top_pi_list';
$dft_vals="mode=".$GR_Vals['mode']."&pop=".($GR_Vals['pop'] ?? '');

## 오늘 매매체크버튼 , 주말에 활용
if(($GR_Vals['today_open'] ?? '')=='on')       { setcookie('opt[today_open]',1,time()+12800,'/');  $admin_info['today_open']=1; }
elseif(($GR_Vals['today_open'] ?? '')=='off' or ($GR_Vals['opt'] ?? '')=='finish')  { setcookie('opt[today_open]',1,time()-3600,'/'); $admin_info['today_open']=0; }

if(!empty($admin_info['today_open'])) $today_open_tag="<img src='../img/check_on.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?$dft_vals&uDate=".($GR_Vals['uDate'] ?? '')."&today_open=off'\"> ";
else  $today_open_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?$dft_vals&uDate=".($GR_Vals['uDate'] ?? '')."&today_open=on'\"> ";

# 5분봉 50억 횟수 업데이트
if(!empty($GR_Vals['max_times'])) top_price_get_stock_info($s_value,$GR_Vals,"update",$connect);

$opt_deco['type']=21; $opt_deco['str']="%"; $opt_deco['font']="17px;";
$opt_float['type']=22;
$opt_rank['type']=31;
$GR_Vals['all']=1;

$dta['qry']="SELECT uDate  FROM `tbl_daily_stock_vol`  group by uDate desc  limit 0,9";                                                                                                                        
$dta['cur_day']=$GR_Vals['uDate'] ?? '';
$dta['urls']="$cur_php?mode=top_pi_list&opt=".($GR_Vals['opt'] ?? '')."&all=".$GR_Vals['all']."&uDate=";
$dta['tbl']=array('width'=>"100px;",'height'=>"40px;");
$get_date_list= get_date_List($dta,$connect);

$yesterday = $get_date_list['yesterday'];
$cur_today = date("Y-m-d");
$cur_hour = date("H");
$srch_today = $get_date_list['today'];



// 순정 PDO로 전일 상한가 종목 불러오기 (날짜는 변수 바인딩)

$sql_limit_stock = "SELECT no, stock_code, stock_name, stock_limit_high_price, stock_price, stock_limit_finish_price, stock_vol_max_rate 
                    FROM tbl_daily_stock_vol 
                    WHERE uDate = :yesterday AND stock_limit_vol > 0";
$stmt_limit_stock = $pdo->prepare($sql_limit_stock);
$stmt_limit_stock->execute(['yesterday' => $yesterday]);
$limit_stocks = $stmt_limit_stock->fetchAll(PDO::FETCH_ASSOC); 
// 

if ($cur_today == $srch_today && $chk_finish != $cur_today) {
    $limit_high_price_update = "<a href='$cur_php?$dft_vals&opt=limit_stock'>*전일 상한가종목 당일 최고가격 업데이트</a>";
} else {
    $limit_high_price_update = "";
}

$limit_tags = "
    <tr><td></td>
    <td colspan='" . ($max_colspan - 1) . "'>
        <table style='border: 1px dashed orange; border-radius: 10px; background-color:#F7F8E0; border-spacing:0px; padding:5px; font-size:13px;' width='100%' align='center' border='0'>
            <tr><td colspan='" . ($max_colspan - 1) . "' style='font-weight:bold;'>*전일 상한가종목:: 당일 상승률 (<img src='../img/up_arr.gif'>최고 상승률) " . $limit_high_price_update . "</td></tr>
            <tr height='30px;'>";

// 2. 업데이트가 필요한 경우, 루프 바깥에서 업데이트 쿼리를 딱 1번만 장전
$is_update_mode = (($GR_Vals['opt'] ?? '') == 'limit_stock' || ($GR_Vals['opt'] ?? '') == 'finish');
if ($is_update_mode) {
    $stmt_limit_up = $pdo->prepare("UPDATE tbl_daily_stock_vol 
                                    SET stock_limit_high_price = :high_price, 
                                        stock_limit_finish_price = :finish_price 
                                    WHERE no = :no");
}

if (!empty($limit_stocks)) {
    foreach ($limit_stocks as $limit_no => $limit_value) {
        
        $limit_stock_info = $stockRepo->getStockInfo($limit_value['stock_code']); // 정보가져옴
        
        if ($is_update_mode) {
            $opt_reload = 1;
            $stmt_limit_up->execute([
                'high_price'   => $limit_stock_info['stock_high_price'] ?? 0,
                'finish_price' => $limit_stock_info['stock_price'] ?? 0,
                'no'           => $limit_value['no']
            ]);
        }

        $limit_high_rate = 0;
        $limit_finish_rate = 0;

        if (!empty($limit_value['stock_limit_high_price']) && !empty($limit_value['stock_price']) && $limit_value['stock_price'] != 0) {                           
            $limit_high_rate = round(($limit_value['stock_limit_high_price'] / $limit_value['stock_price'] - 1) * 100, 2); 
            $limit_finish_rate = round(($limit_value['stock_limit_finish_price'] / $limit_value['stock_price'] - 1) * 100, 2);                      
        }

        $mode_no = $limit_no % 2;                                                                                                                                                                                                                
        if ($mode_no == 0 && $limit_no > 1) $limit_tags .= "</tr><tr height='30px;'>";
        
        $limit_tags .= "<td width='130px;'>" . $limit_value['stock_name'] . "</td>
                        <td align='left' width='150px;'>" . deco_txt($limit_finish_rate, 13, 0) . "  [ <img src='../img/up_arr.gif'>" . deco_txt($limit_high_rate, 13, 0) . " ] </td>
                        <td width='90px;'> x배 (<span style='color:red;font-weight:bold;'>" . deco_txt($limit_value['stock_vol_max_rate'], 31, 0) . "</span>)</td><td></td>";
    }
}
$limit_tags .= "</tr></table></td></tr>";
################### 끝 : 전일 상한가 종목 리스트

// 🚀 [최적화 1] 해당일 매매갯수 (mysqli ➔ 순정 PDO 교체)
$today_tr_qry = "";
if(($GR_Vals['opt'] ?? '')=='today_tr') {    
    $stmt_tr_cnt = $pdo->prepare("SELECT COUNT(DISTINCT stock_code) FROM tbl_trade_review WHERE sell_Date = :today");
    $stmt_tr_cnt->execute(['today' => $srch_today]);
    $get_stock_cnt = $stmt_tr_cnt->fetchColumn() ?: 0;
    
    $stmt_daily_cnt = $pdo->prepare("SELECT count(*) as cnt FROM tbl_daily_stock_vol WHERE today_tr_no > 0 AND uDate = :today");
    $stmt_daily_cnt->execute(['today' => $srch_today]);
    $get_daily_stock_cnt = $stmt_daily_cnt->fetch(PDO::FETCH_ASSOC) ?: ['cnt' => 0];
}

#해당일 뉴스/코멘트 불러오기
$rel_stock_news=top_pi_get_stock_news_info($srch_today,"day",$connect);
$rel_stock_cmt=get_stock_cmt(0,$srch_today,"day",$connect);

$chk_pass_day = 0;
if(strtotime($cur_today)>strtotime($srch_today))   $chk_pass_day=1; 
if(!empty($admin_info['today_open']))  $chk_pass_day=0;
if($chk_pass_day) $GR_Vals['chk_pass_day']=$chk_pass_day;

$order_str="order by today_top_pick desc,";
if(empty($GR_Vals['opt'])) $order_str.=" up_On desc,new_On desc,  stock_rate desc";

$today_tr_tags="<a onclick=\"open_popUp('','',61);\" style='cursor:hand;'>핀업테마</a> | <a href='$cur_php?mode=top_pi_etc' target='news_d4'>시간외</a> |<a href='$cur_php?mode=stock_std_list&uDate=".$srch_today."' target='news_d4'>체결상세</a> | <a onclick=\"open_popUp('','',52);\" style='cursor:hand;'>PopUp</a> | <a onclick=\"javascript:openclub2('daily_news.php?mode=sal','width=1260,height=1800','stock_analysis')\" style='cursor:hand;'>전략종목</a> ";

if(($GR_Vals['opt'] ?? '')=='today_tr') {   
    $today_tr_qry="and ( today_tr_no>0 or grp_cnt>0 ) ";  
    $order_str.=" today_tr_cap desc, stock_rate desc, stock_5m50_times desc, stock_vol_1m_cap desc ";
    $today_tr_tags.="<a onclick=\"location.href='$cur_php?$dft_vals&uDate=".($GR_Vals['uDate'] ?? '')."'\" style='cursor:hand;'><img src='../img/check_on.gif'> <span style='color:red;font-weight:bold;'>매매(".$get_daily_stock_cnt['cnt']."/".$get_stock_cnt.")</span>";
} else { 
    $today_tr_tags.="<a onclick=\"location.href='$cur_php?$dft_vals&opt=today_tr&uDate=".($GR_Vals['uDate'] ?? '')."'\"  style='cursor:hand;'><img src='../img/check_off.gif'></a> 매매";
}

if(($GR_Vals['opt'] ?? '')=='max_times_day') { 
    $order_str.=" stock_box_times_5m desc,   stock_rate desc";
    $today_tr_tags.=" <a onclick=\"location.href='$cur_php?$dft_vals&uDate=".($GR_Vals['uDate'] ?? '')."'\" style='cursor:hand;'><img src='../img/check_on.gif'>박스";
} else {
    $today_tr_tags.=" <a onclick=\"location.href='$cur_php?$dft_vals&opt=max_times_day&uDate=".($GR_Vals['uDate'] ?? '')."'\"  style='cursor:hand;'><img src='../img/check_off.gif'></a>박스";
}

if(($GR_Vals['opt'] ?? '')=='max_times_record') {
    $order_str.=" stock_new_record desc, stock_vol_max_rate desc,stock_5m50_times desc,   stock_rate desc  ";
    $today_tr_tags.=" <a onclick=\"location.href='$cur_php?$dft_vals&uDate=".($GR_Vals['uDate'] ?? '')."'\" style='cursor:hand;'><img src='../img/check_on.gif'> (신고가)";
} else {
    $today_tr_tags.=" <a onclick=\"location.href='$cur_php?$dft_vals&opt=max_times_record&uDate=".($GR_Vals['uDate'] ?? '')."'\"  style='cursor:hand;'><img src='../img/check_off.gif'></a> (신고가)";
}

$today_tr_tags.=" | <img src='../img/pen.gif'><a  href='prj_yehior.php?mode=tthai&go=2'>체결내역 입력</a> ";

$normal_qry = "";
if(empty($GR_Vals['all']) and ($GR_Vals['opt'] ?? '')!='finish') $normal_qry=" and ((stock_rate>=8  and stock_vol/stock_vol_float>0.2) or today_tr_cap>1  or stock_vol_float=0  or stock_rate>12 or grp_cnt>0)";

// 🚀 [최적화 2] 메인 주식 리스트 쿼리 (php_mysql_query ➔ 순정 PDO 교체)
$sql_stock = "SELECT * FROM tbl_daily_stock_vol WHERE uDate='{$srch_today}' {$normal_qry} {$today_tr_qry} {$order_str}";
$result_stock = $pdo->query($sql_stock)->fetchAll(PDO::FETCH_ASSOC); 

if($test_on) echo $sql_stock;

$tot_cnt= "(<span style='color:red;font-weight:bold;font-size:20px;'>#".count($result_stock)."</span>)";

// 종목과 연관된 테마 불러오기 (PDO 변수로 수정)
$get_thema_no=top_pi_get_thema_all_pdo($sql_stock,$pdo);

$finish_tags = "";
if(empty($admin_info['today_open']) && $chk_finish!=$cur_today and !$chk_pass_day and $cur_hour>=16) $finish_tags.="| <a href='$cur_php?$dft_vals&uDate=".$srch_today."&opt=finish' style='font-size:20px;color:red;font-weight:bold;'>마감</a>(200억미만D)";

if (($GR_Vals['opt'] ?? '') === 'finish') {
    # 마감 후에 대금 300억 이하 + 미거래종목은 안전하게 삭제함.
    $sql_del_finish = "DELETE FROM tbl_daily_stock_vol 
                       WHERE uDate = :today 
                         AND today_tr_cap = 0 
                         AND stock_vol_cap < 300 
                         AND stock_vol_1m_max = 0";
    $stmt_del_finish = $pdo->prepare($sql_del_finish);
    $stmt_del_finish->execute(['today' => $srch_today]); 
    $opt_reload = 1;
}

if(!empty($opt_reload)) {
    echo "<body onload=location.href='$cur_php?$dft_vals&uDate=".$srch_today."&all=1'>";     
    exit;
}

echo "<html><body>";
echo $style_css ?? '';
echo "<a name='top_hdr'></a>";

// 🚀 [최적화 완료] 자바스크립트 영역 (Heredoc)
echo <<<HTML
<script type="text/javascript">
    const opt_val = '{$GR_Vals['opt']}';

    function open_popUp(stock_code, no, opt) {
        let wth = 1230; let hgt = 1200; let url = '';
        switch (parseInt(opt, 10)) {
            case 1: url = '$cur_php?mode=vol_float&stock_code=' + stock_code + '&no=' + no; wth = 320; hgt = 220; break;
            case 2: url = '$cur_php?mode=vol_max&opt=' + opt_val + '&stock_code=' + stock_code + '&no=' + no; wth = 430; hgt = 320; break;
            case 3: url = '$cur_php?mode=top_grp&opt=' + opt_val + '&grp_cnt=' + stock_code + '&all=1'; wth = 790; hgt = 350; break;
            case 4: url = 'prj_yehior.php?mode=tdv&no=' + no; break;
            case 5: url = '$cur_php?mode=top_pi_grp&uDate=$srch_today&opt=' + opt_val + '&type=view&no=' + no; hgt = 1500; break;
            case 52: url = '$cur_php?mode=top_pi_list&pop=1&opt=' + opt_val; wth = 950; break;
            case 53: url = 'daily_report.php?mode=report_reaD&no=0000011'; wth = 790; hgt = 350; break;
            case 6: hgt = ($cur_hour < 16) ? 800 : 2000; url = '$cur_php?mode=top_pi_grp&type=write&uDate=$srch_today&opt=' + opt_val + '&today_tr_no=' + stock_code + '&no=' + no; break;
            case 61: url = 'https://finance.finup.co.kr/Lab/ThemeLog/popup?Fullscreen=true'; wth = 1120; hgt = 800; break;
            case 7: url = '$cur_php?mode=stock_std_cmt&mode_two=grp_view&opt=' + opt_val + '&grp_no=' + no; wth = 3800; hgt = 1300; break;
            default: return;
        }
        const popupX = (window.screen.width / 2) - (wth / 2);
        const popupY = (window.screen.height / 2) - (hgt / 2);
        const size = 'width=' + wth + ',height=' + hgt + ',left=' + popupX + ',top=' + popupY + ',toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes';
        const opt_win = opt + '_win';
        const n = window.open(url, opt_win, size);
        if (n) n.focus();
    }
    function submit_Confirm(v) { if (v.top_price.value.trim() === '') { alert('내용없음'); return; } v.submit(); }
    function submit_Tick_num(no, stock_vol_tick_time, stock_vol_tick_cap, stock_vol_tick_max) {
        const get_tick_num = prompt('갯수'); if (!get_tick_num || get_tick_num == 0) return;
        let cur_max = prompt('최대Tick', stock_vol_tick_max);
        let cur_time = stock_vol_tick_time;
        let cur_cap = stock_vol_tick_cap;
        if (cur_time == 0) { cur_time = prompt('시간'); cur_cap = prompt('금액'); }
        const go_to_url = '$cur_php?mode=vol_max&type=tick_num&no=' + no + '&stock_vol_tick_time=' + cur_time + '&stock_vol_tick_cap=' + cur_cap + '&stock_vol_tick_max=' + cur_max + '&stock_vol_tick_num=' + get_tick_num;
        window.open(go_to_url, 'news_d3');
    }
    function submit_srch_Confirm() {
        const srchInput = document.getElementById('srch_jongmok');
        const hiddenInput = document.getElementById('hidden_key');
        const go_keys = srchInput.value; hiddenInput.value = go_keys; srchInput.style.backgroundColor = 'yellow';
        const go_to_url = '$cur_php?mode=pop_url&pop_type=10010&stock_name=' + go_keys;
        window.open(go_to_url, 'pop', 'width=2,height=2,left=0,top=0');
    }
    function change_color(btn_no) { const el = document.getElementById(btn_no); if (el) el.style.backgroundColor = 'yellow'; }
    function disp_hidden_key() { const hidden_key = document.getElementById('hidden_key').value; document.getElementById('srch_jongmok').value = hidden_key; }
</script>
HTML;

$tr_text_input= "<table border=0>
    <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>   
    <input type=\"hidden\" name=\"mode\" value=\"top_pi_ins\">
    <input type=\"hidden\" name=\"mode_two\" value=\"update\">                                              
    <tr height='30px;' style='vertical-align:top;'>
    <td>
    <input type=button onclick=\"javascript:submit_Confirm(document.myform);\" value='등 록(TR0181)' style='width:100px;height:30px;cursor:hand;'>
    <textarea name='top_price' style=\"vertical-align:top;width:405px; height:30px;border:dashed 1px gray;border-radius: 7px;\"></textarea><span style='font-weight:bold;color:red;font-size:12px;'>*거래대금체크</span>
    </form>
    </td><td width=20px;></td>
    <td>                                                    
    <img src='../img/smile.gif'> <input type='text'  size='10'  name='key_word' id='srch_jongmok' value='종목검색' class=form_nc style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:30px;font-size:17px;'  onfocus=this.value=''; onBlur=\"disp_hidden_key();\"; onkeyup=\"if(window.event.keyCode==13){submit_srch_Confirm();}\"><input type=hidden id='hidden_key'>
    </td></tr></table>";

echo "
<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  width=".($tbl_width['i5t']*0.98)."  border=0> "; 
echo "<Tr><td   style='border: 0px dashed orange; border-radius: 6px; background-color:#F8E0EC; border-spacing:3px;font-size:13px;'colspan=".$max_colspan.">".$get_date_list['tags']."</td></tr>";
echo "<tr><td colspan=2>".$today_open_tag."오늘의 상승 종목 $tot_cnt  </td><td colspan=".($max_colspan-2)."> $today_tr_tags | $finish_tags</td></tr>";
echo "<tr><td colspan=".($max_colspan).">".$tr_text_input. ($search_tags ?? '')."</td></tr>";

if(!($chk_pass_day) and $cur_hour<=16) {    
    echo "<tr><td colspan=".($max_colspan).">".($up_Time_Tags ?? '')."</td></tr>"; 
    $high_price_on=1;                                   
} else {
    $high_price_on=0;                                   
}

// 🚀 [최적화 3] 루프 바깥에서 코멘트(N+1) 쿼리 미리 장전!
$stmt_grp = $pdo->prepare("SELECT cmt, no, tr_yes, uDate, stock_vol_no FROM tbl_daily_stock_vol_grp WHERE stock_vol_no = :no");

$nn = 0;
$disp_tags = "";
$cur_stock_list = [];
$thema_list = [];
$thema_stock_name = [];
$thema_stock_rate = [];
$rate_font = [];

if(!empty($result_stock)) {
    foreach($result_stock as $s_no => $s_value){
        $nn++;
        $today_tr_bgcolor=""; $cur_price=""; $list_out_color="";
        $grp_cmt_tag=""; $final_cmt_tag=""; $stock_today_trade_tag="";
        $grp_all_view=""; $stock_vol_max_rate_tag=""; $checked_stock="";
        $rel_stock_news_tag=""; $rel_stock_cmt_tag=""; $up_Times_tag="";
        $tick_gc_ico=""; $thema_tags="";

        $rel_thema_no=$get_thema_no[$s_value['stock_code']] ?? null;
        $cur_stock_list[]= $s_value['stock_name'];

        if($rel_thema_no) {                                                                 
            $thema_tags.="<br><span style='color:#298A08;font-size:15px;'>";
            foreach($rel_thema_no as $t_no => $t_value) {                                                                                                                   
                $thema_name_str=$all_thema_name[$t_value]['thema_name'];
                $thema_no_str=$all_thema_name[$t_value]['thema_no'];
                $thema_list[$t_value][]="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10021&thema_no=".$thema_no_str."&key_word=".$thema_name_str."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$thema_name_str."</a>";
                $thema_stock_name[$t_value][]="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_name=".$s_value['stock_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$s_value['stock_name']."</a>";
                if($s_value['stock_rate']>7) { 
                    $thema_stock_rate[$t_value][]=cur_deco_txt($opt_deco,$s_value['stock_rate'],10,1,10);
                    $rate_font[$t_value][]="<span style='color:black;font-size:14px;'>"; 
                }
                $thema_tags.="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10021&thema_no=".$thema_no_str."&key_word=".$thema_name_str."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$thema_name_str."<Br>";
            }
            $thema_tags.="</span>";
        }

        $vals=array('type'=>'trade_multi_view','stock_code'=>$s_value['stock_code'],'uDate'=>$s_value['uDate']);
        $get_stock_tr=get_daily_tr_info($vals,$connect);
        
        if(!empty($get_stock_tr['value']['no']))   { 

            $cur_stock_price = $stockRepo->getStockInfo(['code' => $s_value['stock_code']]);
        
            $stock_today_trade_tag=$get_stock_tr['tag'];
            if($get_stock_tr['value']['sell_price']<$cur_stock_price) $up_side_tag="<span style='color:red;font-weight:bold;font-size:19px;'>"; else $up_side_tag="";
            $cur_price="<br><br>(매도後)$up_side_tag".deco_txt(($cur_stock_price/$get_stock_tr['value']['sell_price']-1)*100,131,0)."</span>";
        }

        if(($GR_Vals['no'] ?? '') == $s_value['no']) $checked_stock="style='background-color:yellow;'";

        if( !($chk_pass_day)) {     
            $up_vol_click="<a onclick=\"open_popUp('".$s_value['stock_code']."','".$s_value['no']."',2);\" style='cursor:hand;'>";
            $up_vol_float_click="<a onclick=\"open_popUp('".$s_value['stock_code']."','".$s_value['no']."',1);\" style='cursor:hand;'>";           
            $max_vol_tags="<td align=left colspan=4><img src='../img/ic/16-heart-gold-l.png' onclick=\"open_popUp('".$s_value['stock_code']."','".$s_value['no']."',2);\" style='cursor:hand;'></td>"; 
        } else {  
            $up_vol_click=""; $up_vol_float_click=""; $max_vol_tags="<td colspan=5></td>"; 
        }   
            
        if($s_value['grp_cnt']) {  
            $grp_all_view=" <img src='../img/bul59.gif'><a onclick=\"open_popUp('".$s_value['stock_code']."','".$s_value['no']."',5);\" style='cursor:hand;'>(전체보기)</a>";  
            $today_tr_bgcolor="background-color:#FBEFEF";  
        } else {
            if(!empty($get_stock_tr['value']['no'])) $grp_all_view="<a onclick=\"  open_popUp('".$s_value['stock_code']."','".$s_value['no']."',6);\" style='cursor:hand;'><img src='../img/c7.gif' title='그래프 등록'> <span style='font-size:14px;color:blue;font-weight:bold;text-decoration:underline;'>그래프 등록!</span></a>";                                                                 
        }

        if($s_value['today_tr_no'] ) $today_tr_bgcolor="background-color:yellow;"; 
        $stock_stactic_tags = top_price_get_stock_info($s_value,$GR_Vals,"insert",$connect);                                                              

        if($s_value['stock_vol_max_rate']>0)$stock_vol_max_rate_tag="x배 <span style='font-size:17px;color:black;font-weight:bold;text-decoration:underline;'>".$s_value['stock_vol_max_rate']."</span>";

        $final_stock_info_tag="<tr style='height:50px;".$today_tr_bgcolor."'><td  colspan=".($max_colspan-3).">&nbsp; ".$stock_stactic_tags."</td><td >".$stock_vol_max_rate_tag."</td></tr>";
        
        if(!$s_value['grp_cnt'] and empty($get_stock_tr['value']['no']))  $cmt_box_color="none"; else  $cmt_box_color="blue";

        $grp_cmt_tag="<tr style='height:30px;".$today_tr_bgcolor."'><td colspan=2></td><Td colspan=".($max_colspan-3).">
        <table style='border: 1px dashed $cmt_box_color; border-radius: 10px;border-spacing:2px;padding:5px;font-size:13px;'  width=100% align=center border=0>";
        
        if($s_value['grp_cnt'] ) {
            // 🚀 [최적화 3 발사] 장전된 코멘트 쿼리 실행
            $stmt_grp->execute(['no' => $s_value['no']]);
            $result_grp = $stmt_grp->fetchAll(PDO::FETCH_ASSOC);
                                                                                        
            foreach($result_grp as $g_value){   
                if($s_value['today_tr_no'] and $g_value['tr_yes']) {
                    if($g_value['tr_yes']>0) $tr_yes_tag="<img src='../img/imoticon/tt_sm.gif'>(".$g_value['tr_yes'].") &nbsp;";
                    else  $tr_yes_tag="<img src='../img/imoticon/angry_sm.gif'>(".$g_value['tr_yes'].") &nbsp;";
                } else $tr_yes_tag="<img src='../img/3d.gif'> ";
            
                $short_cmt= shorten_Str($g_value['cmt'],40,'..');
                $grp_cmt_tag.="<tr style='height:30px;".$today_tr_bgcolor."'><td colspan=2>&nbsp;  ".$tr_yes_tag.explode(' ',$g_value['uDate'])[1]."<a onclick=\"open_popUp('".$s_value['stock_code']."','".$g_value['stock_vol_no']."',5);\" style='cursor:hand;'> ".$short_cmt."</td></tr>";
            }                                                                                            
        } 

        $grp_cmt_tag.="<tr style='height:30px;".$today_tr_bgcolor."'><td  width=110px;> &nbsp; ".$grp_all_view." </td><td>".$stock_today_trade_tag."</td></tr>";
        $grp_cmt_tag.="</table></td><td></td></tr><tr style='height:10px;".$today_tr_bgcolor."'></tr>";

        if(!$s_value['grp_cnt'] and empty($get_stock_tr['value']['no'])) $grp_cmt_tag="";
        if(!empty($rel_stock_news['tag'][$s_value['stock_code']])) $rel_stock_news_tag="<tr style='height:40px;".$today_tr_bgcolor."'><td colspan=2></td><Td colspan=".($max_colspan-2).">".$rel_stock_news['tag'][$s_value['stock_code']]."</td></tr>";
        if(!empty($rel_stock_cmt[$s_value['stock_code']]))  $rel_stock_cmt_tag="<tr><td colspan=2></td><Td colspan=".($max_colspan-2)."><table  style='border: 1px dashed orange; border-radius: 7px; background-color:white; border-spacing:3px;font-size:12px;color:#2E64FE;height:30px;padding:3px;' width=96%><tr><td>".nl2br($rel_stock_cmt[$s_value['stock_code']]['stock_cmt'])."</td></tr></table></td></tr>";

        if($s_value['today_top_pick']) { 
            $today_topick_tags="<a onclick=\"location.href='$cur_php?$dft_vals&max_times=41&no=".$s_value['no']."&uDate=".($GR_Vals['uDate'] ?? '').($dft_opt_tag ?? '')."'\"  style='cursor:hand;'><img src='../img/check_on.gif'>";
        } else $today_topick_tags="<a onclick=\"location.href='$cur_php?$dft_vals&max_times=4&no=".$s_value['no']."&uDate=".($GR_Vals['uDate'] ?? '').($dft_opt_tag ?? '')."'\"  style='cursor:hand;'><img src='../img/check_off.gif'></a>";

        $top_link="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_name=".$s_value['stock_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>";
        $final_vol_tag = $s_value['final_vol'] ? "<img src='../img/check_on.gif'>" : "";
        $stock_1m_vol_cap=""; $stock_vol_cap="";

        if($s_value['stock_vol_1m_cap']>0){  
            $stock_1m_vol_cap="<br>(".deco_txt($s_value['stock_vol_1m_cap'],31,0)." 억)";
            if($s_value['stock_vol_float'] > 0) $stock_1m_rate=$s_value['stock_vol_1m_max']/$s_value['stock_vol_float'];
        }

        if($s_value['stock_price_high']>0) {    
            $stock_price_high_rate= (($s_value['stock_price']/$s_value['stock_price_high'])-1)*100;                                                                                                     
            if($stock_price_high_rate<>0) $stock_price_high_tag=deco_txt($stock_price_high_rate,11,0);
            else $stock_price_high_tag="";
        } else {                                                                                            
            if($s_value['stock_price']>0 && !empty($s_value['stock_price_first'])) $stock_price_high_tag=deco_txt($s_value['stock_price']/$s_value['stock_price_first']-1,2,0);
            else {  
                $stock_price_high_tag="<span style='font-weight:bold;color:red;'>New</span>";       
                if($s_value['up_On']) $today_tr_bgcolor="background-color:yellow"; 
            }
            if(!$s_value['up_On'] ) {
                $stock_price_high_tag="<span style='font-weight:bold;color:blue;'>★ List Out</span>";                                                                                                           
            }
        }

        if(!$s_value['up_On'] ) $list_out_style="<span style='color:blue;font-weight:bold;font-size:12px;'>*</span>";  
        else {
            if($s_value['today_top_pick'] ) $list_out_style="<span style='font-size:17px;font-weight:bold;color:red;'>";                                                                                                
            else $list_out_style="<span style='font-size:17px;'>";
        }

        if($s_value['stock_limit_vol']>0) {                                                                                                     
            $stock_limt_vol_tags="<td>".cur_deco_txt($opt_deco,$s_value['stock_limit_vol']/$s_value['stock_vol_float']*100,5,0,0)."<br>(".deco_txt($s_value['stock_limit_vol'],3,0).")</td>";
            $stock_limt_ico=" (<img src='../img/tp1_on.gif'>) ";
        } else { $stock_limt_vol_tags=""; $stock_limt_ico=""; }

        if($s_value['stock_vol_cap']>0)  $stock_vol_cap="<br>(".deco_txt($s_value['stock_vol_cap'],133,500)." 억)";                                                                                                         

        if($s_value['stock_vol_float'])     {
            $vol_float_tags="<Td align=center>$up_vol_float_click".cur_deco_txt($opt_deco,$s_value['stock_vol']/$s_value['stock_vol_float']*100,70,1,70).$stock_vol_cap."</td> ";
        } else {  
            $vol_float_tags="<td align=center><a onclick=\"open_popUp('".$s_value['stock_code']."','".$s_value['no']."',1);\" style='color:blue;cursor:hand;font-size:30px;'>n/a</a></td>";   
            $max_vol_tags="";
        }
                                                                                                        
        if($s_value['stock_vol_1m_max']) $max_vol_tags="<td>$final_vol_tag</tD><td>$up_vol_click".deco_txt($s_value['stock_vol_1m_max'],3,0).$stock_1m_vol_cap."</td>";
        else $max_vol_tags="<td>".$up_vol_click."<img src='../img/ic/16-heart-red-xs.png'></tD><td></td>";

        $stock_tick_num_click_tags="onclick=\"submit_Tick_num('".$s_value['no']."','".$s_value['stock_vol_tick_time']."','".$s_value['stock_vol_tick_cap']."','".$s_value['stock_vol_tick_max']."');\" style='cursor:hand;'";

        if($s_value['stock_vol_tick_num']) { 
            $tick_time = str_split($s_value['stock_vol_tick_time'],2);
            if(!empty($s_value['stock_vol_tick_time2']) && $s_value['stock_vol_tick_time2']>0) {
                $tick_gap_calc=tick_time_gap_calc($s_value['stock_vol_tick_time'],$s_value['stock_vol_tick_time2']);                                                                                                    
                if($s_value['stock_vol_tick_gc']) $tick_gc_ico="<img src='../img/up_arr.gif'> ";                                                                                                        
                $tick_gap_time=$tick_gc_ico."<span style='font-weight:bold;color:red;'>".$tick_gap_calc."分</span>";                                                                                                              
            } else {
                $tick_gap_time = "";
            }
            $max_vol_tags.="<td align=center width=59px;>".$tick_gap_time."</td><td align=center  ".$stock_tick_num_click_tags.">T(".deco_txt($s_value['stock_vol_tick_num'],3,0).")<br>(".$tick_time[0].":".($tick_time[1] ?? '00').")</td>";
        } else  $max_vol_tags.="<td align=center colspan=3></td>";

        if($s_value['stock_vol_tick_max']) { 
            $max_vol_tags.="<td align=center width=70px;>m(".deco_txt($s_value['stock_vol_tick_max'],3,0).")<br>".deco_txt($s_value['stock_vol_tick_cap'],3,0)."억</td>";
        } else  $max_vol_tags.="";
                                                                                                    
        $disp_tags.= "<tr><td><a name='".$s_value['no']."' style='text-decoration:none;'></td></tr>";
        $disp_tags.= "<tr style='height:40px;".$today_tr_bgcolor."'>
                        <td rowspan=2  align=center><a href='#top_hdr' style='text-decoration:none;' valign=top>".$today_topick_tags."<br></td>
                        <Td $checked_stock id='".$s_value['no']."' rowspan=2>".$top_link.$list_out_style.$s_value['stock_name']."</a>".$stock_limt_ico."</span>".$cur_price."<br>".$thema_tags."</td>
                        <Td align=right>".$stock_price_high_tag."</td><Td>".deco_txt($s_value['stock_rate'],11,0)."</td>
                          $vol_float_tags                                                                                                    
                          $max_vol_tags             
                  </tr>";
        $disp_tags.= $final_stock_info_tag;
        $disp_tags.= $grp_cmt_tag; 
        $disp_tags.= $rel_stock_news_tag;                                                                                                
        $disp_tags.=$rel_stock_cmt_tag;
        $disp_tags.= $updn_uTime_tag ?? ''; 
        $disp_tags.= "<tr>".($dot_line ?? '')."</tr>";
    }
} 

## 테마 리스트
if (!empty($thema_list)) arsort($thema_list);
$tl_tags="<tr style='height:50px;' align=center><td colspan=".($max_colspan).">
     <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  width=100% align=center border=0>";

$stock_mod=4;
$stock_name_tag_width=($tbl_width['i5t']*0.95)/$stock_mod;

if (!empty($thema_list)) {
    $j = 0;
    foreach($thema_list as $tl_no => $tl_value){
        if(count($tl_value)>1) {
            $j++; $sj=0;
            $st_tags="<table><tr >";
            foreach($thema_stock_name[$tl_no] as $st_no => $st_value){
                $mode_no=$sj%$stock_mod;                                
                if($mode_no==0 and $sj>1) $st_tags.= "</tr><Tr>";
                $st_tags.="<Td style='font-size:11px;color:#C9AFAF;padding-bottom: 1px;padding-top: 5px;' nowrap>".($rate_font[$tl_no][$st_no] ?? '').$st_value."</span></a>".($thema_stock_rate[$tl_no][$st_no] ?? '')."</td>";
                $sj++;
            }
            $st_tags.="</tr></table>";
            $tl_tags.= "<tr><td width='15px;'>#".count($tl_value)."</td><td width=125px; nowrap>".$tl_value[0]."  </td><td  style='padding-bottom: 5px;padding-top: 2px;'>$st_tags</td></tr>"; 
            $tl_tags.= "<tr>".($dot_line ?? '')."</tr>";
        }
    }
}
$tl_tags.="</table></td></tr><tr height=15px;><td></td></tr>";

if(empty($GR_Vals['opt'])) echo $tl_tags; 
echo $limit_tags;  

# 종목명 리스트
if(!empty($admin_info['today_open']) && !empty($cur_stock_list)) {
    echo "<tr height=15px;><td></td></tr>";
    sort($cur_stock_list);
    echo "<tr><td colspan=20 align=center><table style='font-size:12px;' border=0><Tr height=30px;>";
    for($cc=0;$cc<count($cur_stock_list);$cc++) {
        $mode_no=$cc%9;                             
        if($mode_no==0 and $cc>1) echo "</tr><Tr height=30px;>";
        echo "<td>".$cur_stock_list[$cc]."</td>";
    }
    echo "</table></td></tr>";
}

echo "<tr height=15px;><td></td></tr>";
echo "<tr style='background-color:yellow;height:50px;' align=center><td width=35px;>Pick</td> <td width=200px;>종목명</td><td  width=70px>등록이후<br>(고점대비)</td> <td>등락률</td><td width=100>유통회전률</td><td width=25px;>종가</td> <td  align=center width=90px;>1분</td><td colspan=3>60Tick</td></tr>";
echo "<tr>".($dot_line ?? '')."</tr>";
echo $disp_tags;
echo "</table> "; 
echo "</body></html>";

if(!empty($GR_Vals['no'])) {
    echo "<body onload=location.href='#".$GR_Vals['no']."'>";     
}
exit;
#################################################################
} # end of  당일 상승률 상위 100 종목
#################################################################


############################################
function top_price_history($connect) {  # t3:: ★★★★★★★★ 당일상승률100 종목 통계
###########################################
global $admin_info;
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');
$G_Stock_Code=$GR_Vals['stock_code'];

$opt=$GR_Vals['opt'];

#종목정보가져오기
   $stock_info=get_stock_info($GR_Vals['stock_code'],$connect); # 현재종목에 대한 리스트			

# 종목 히스토리 가져오기
$query_stock['qry']="SELECT * from `tbl_daily_stock_vol`  where stock_code='".$GR_Vals['stock_code']."'  order by uDate desc limit 0,7" ;
$result_stock=php_mysql_query($query_stock,$connect); 


echo "<html><body>";
echo $style_css;

echo "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px;'  width=100%  >";

echo "<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:14px;color:blue;' align=center  height='49px;' ><td style='font-weight:bold;color:black;font-size:20px;' width=210px; colspan=2>".$stock_info['stock_name']."</td><td colspan=2>상한가잔량 (다음날?)</tD><tD width=100px;>유통회전률</td><td width=100px;>1분</td><td width=120px; colspan=3>60Tick</td><td>주식 분석</td></tr>";

#if($s_value['stock_vol_tot']>0) $vol_float_rate=cur_deco_txt($opt_float,$s_value['stock_vol_float']/$s_value['stock_vol_tot']*100,40,0,0);

if($result_stock['value']) {

																						foreach($result_stock['value'] as $s_no => $s_value){

		
																							 $up_day=calender_str(3,13,$s_value['uDate']);												  

																							 	# 시작 : 5억70억 돌파봉, 일목균형표, H5+첫봉 돌파
																								   $stock_stactic_tags=	top_price_get_stock_info($s_value,$GR_Vals,"display",$connect);																								
																							   	# 끝 : 5억70억 돌파봉, 일목균형표, H5+첫봉 돌파

																							 #고가상승률
																							 	if($s_value['stock_price_high']>0) { 	
																									$prv_stock_price= ceil($s_value['stock_price']/( 1+ $s_value['stock_rate']/100));
																									$stock_high_rate=(($s_value['stock_price_high']/$prv_stock_price)-1)*100;																																																	
																							    	} 
																									else $stock_high_rate=$s_value['stock_rate'];																												

																									if($stock_high_rate>$s_value['stock_rate']) $stock_high_rate_tag="(".cur_deco_txt($opt_deco,$stock_high_rate,15,5,8).")";	
																									else $stock_high_rate_tag="(★)";

																									# 1분 거래량,대금

																									if($s_value['stock_vol_1m_max']>0) $stock_1m_vol_tag=deco_txt($s_value['stock_vol_1m_max'],3,0)." 주<br>(".deco_txt($s_value['stock_vol_1m_cap'],31,0)." 억)";
																										else $stock_1m_vol_tag="";																									

																									#유통회전률
																										if($s_value['stock_vol_float']) $vol_float_tags=cur_deco_txt($opt_deco,$s_value['stock_vol']/$s_value['stock_vol_float']*100,70,1,70)."<br>".deco_txt($s_value['stock_vol_cap'],133,500)."  억</td> ";
																																								
																								# 60Tick
																											   if($s_value['stock_vol_tick_num']) { 
																												   $tick_time = str_split($s_value['stock_vol_tick_time'],2);
																												   $tick_time_tags="# ".deco_txt($s_value['stock_vol_tick_num'],3,0)."<br>(".$tick_time[0].":".$tick_time[1].")";
																												   $tick_cap_tags="# ".deco_txt($s_value['stock_vol_tick_max'],3,0)."<br>".deco_txt($s_value['stock_vol_tick_cap'],3,0)."억";

																												   if($s_value['stock_vol_tick_time2']>0) {																													   																													   
																													   
																													      $tick_gap_calc=tick_time_gap_calc($s_value['stock_vol_tick_time'],$s_value['stock_vol_tick_time2']);																													   
																													      $tick_gap_time="<font style='font-weight:bold;color:red;'>".$tick_gap_calc."分";			
																												   }



																											   } else {
																												   $tick_time_tags="";
																												   $tick_cap_tags="";
																												   $tick_gap_time="";
																											   }

																											   	# 상한가
																										if($s_value['stock_limit_finish_price']) { $stock_limit_vol_tags=deco_txt($s_value['stock_limit_vol'],3,0)."<br>".cur_deco_txt($opt_deco,$s_value['stock_limit_vol']/$stock_info['stock_vol_tot']*100,5,1,0); 
																																								$stock_limit_ico="(<img src='../img/tp1_on.gif'>) ";
																																							   $limit_finish_rate=  cur_deco_txt($opt_deco,round(($s_value['stock_limit_finish_price']/$s_value['stock_price']-1)*100,2),15,1,-10)."<br>".cur_deco_txt($opt_deco,round(($s_value['stock_limit_high_price']/$s_value['stock_price']-1)*100,2),15,1,-10); 		
																										
																										}

																											else { $stock_limit_vol_tags=""; 
																											          $stock_limit_ico="";
																													  $limit_finish_rate="";
																											
																											}


																							echo "<tr style='text-align:center;font-size:14px;'>";

																							echo "<td>".$up_day['unix_str']."</td>";


																							echo "<td align=left>".$stock_limit_ico.deco_txt($s_value['stock_rate'],11,0).$stock_high_rate_tag."</td>";

																							echo "<td >".$stock_limit_vol_tags."</td>";
																							
																							echo "<td >".$limit_finish_rate."</td>";
																							

																							echo "<td >".$vol_float_tags." </td>"; # 유통회전률,거래대금

																							echo "<td >".$stock_1m_vol_tag."</td>"; # 1분봉 거래대금
																							echo "<td >".$tick_gap_time."</td>"; # 120Tick과 240Tick 사이시간
																							echo "<td>".$tick_time_tags."</td>"; # 60Tick 
																							echo "<td >".$tick_cap_tags."</td>"; # 60Tick 
																							
																							

																							echo "<td nowrap>".$stock_stactic_tags."</td>"; # 박스,첫봉,신고가 
																							echo "</tr>";

																							



																						}


} # end of result_stock






echo "</body></html>";


#################################################################
} # end of top_price_history
#################################################################




############################################
function get_scrap_grp($scrap_Vals,$connect) {
###########################################
	   	   
if(!$scrap_Vals['font-size']) $scrap_Vals['font-size']="12px";


if($scrap_Vals['disp']=="scrap_news") {
      $query_grp="SELECT* FROM `tbl_news_scrap_grp` where news_no=".$scrap_Vals['no']." ";
}
elseif($scrap_Vals['disp']=="thema_all") {
 $query_grp="SELECT* FROM `tbl_news_scrap_grp` where thema_no=".$scrap_Vals['no']." order by uDate desc limit 0,2";

$date_on=1;

}


	        $result_grp=mysqli_query($connect,$query_grp); 
	      
		
			foreach($result_grp as $grp_key => $grp_value) { 	
			


			if($date_on) $date_tags="(".$grp_value['uDate'].") ";


	 	  	 $urls="prj_yehior.php?mode=img_pop&type=scrap_grp&no=".$grp_value['no']."";
             $contents=base64_Img_decode($urls,$grp_value['contents'],$scrap_Vals['max_width']);

			if($grp_value) $return_scrap_grp_tags.="<table style='border: 0px dashed black; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;padding:0px;'  width=".$scrap_Vals['max_width']." border=0><tr align=center>
			<td><table style='border: 1px dashed orange; border-radius: 10px; background-color:#F7F8E0; border-spacing:3px;padding:0px;'  width=100%><Tr style='font-size:".$scrap_Vals['font-size'].";'><td><img src='../img/c9.gif'> ".$date_tags.$grp_value['cmt']."</td></tr></table>
			</td></tr>
														<tr><td align=center>".$contents."</td></tr></table>";
														else $return_scrap_grp_tags="";

			}


 return $return_scrap_grp_tags;

#################################################################
} # end of get_rel_stock_tags
#################################################################



############################################
function get_scrap_news($scrap_Vals,$connect) {
###########################################
	   	   
if(!$scrap_Vals['font-style']) $scrap_Vals['font-style']="font-size:12px;";

if($scrap_Vals['disp']=="scrap_news") {
     $query_news="SELECT* FROM `tbl_news_scrap` where no=".$scrap_Vals['no']." ";
}

	        $result_news=mysqli_query($connect,$query_news); 
	        $news_value=mysqli_fetch_array($result_news);

			$news_tit= shorten_Str($news_value['news_title'],100,'');


			if($news_value) $return_scrap_news_tags="<table><tr><td><a href='".$news_value['news_link']."' target='news_d5'  style='".$scrap_Vals['font-style']."'>".$news_tit."</td></tr></table>";
			else $return_scrap_news_tags="";


 return $return_scrap_news_tags;

#################################################################
} # end of get_rel_stock_tags
#################################################################

############################################
function get_rel_stock_Rank($Rank_Vals,$connect) { # 당일 상승, 최고상승률 표시
###########################################
	   	   
#if(!$scrap_Vals['font-size']) $scrap_Vals['font-size']="12px";

if(empty($Rank_Vals['ms'])) $ms=3; else $ms=$Rank_Vals['ms'];
if(empty($Rank_Vals['font-size'])) $stock_font_size='12px'; else $stock_font_size=$Rank_Vals['font-size'];



	 
																																										$fst_stock_tags="";
																																										$snd_stock_tags="";
																																										$trd_stock_tags="";
																																										$fst=0;
																																										$snd=0;
																																										$trd=0;
																																																																																		

	  $fst_stock_tags="<table style='font-size:".$stock_font_size."' border=0><tr>";
																																								  $snd_stock_tags="<table style='font-size:".$stock_font_size."' border=0><tr>";
																																								  $trd_stock_tags="<table style='font-size:".$stock_font_size."'><tr>";

																																								  $high_stock_font="<font style='font-weight:bold;'>";

  	        $query_Rank="SELECT * FROM `tbl_daily_thema_stock` where rel_news_no='".$Rank_Vals['rel_news_no']."' ";
	        $result_Rank=mysqli_query($connect,$query_Rank); 

		

     			foreach($result_Rank as $stock_key => $stock_value) { 	
					
					$mode_fst=$fst%$ms;			
                   $mode_snd=$snd%$ms;			
                 	$mode_trd=$trd%$ms;			

						$chk_New_bg="";

                   $cur_stock_info=get_stock_info($stock_value['stock_code'],$connect); # 현재종목에 대한 리스트			
				   
				   		if($stock_value['stock_high_price']) $high_price_rate= round(($stock_value['stock_high_price']/($stock_value['stock_price']-$stock_value['stock_yrate'])-1)*100,2);																																											 

																																										if($stock_value['chk_New']) { $chk_New_bg="background-color:red;color:white;";  # 신규종목은 노란색배경
																																										#$chk_New_ico="<img src='../img/n2.gif'>";
																																										}

																																										if($stock_value['stock_rate']>=10) $stock_name_style="font-weight:bold;font-size:14px;".$chk_New_bg; 
																																										elseif($stock_value['stock_rate']<5)  $stock_name_style="color:#A4A4A4;".$chk_New_bg; 
																																										else $stock_name_style=$chk_New_bg;

																																										

																																										$jongmok_open="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_code=".$stock_value['stock_code']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>"; 																																										
																																										$thema_Rate_Vals=array('finish_rate'=>$stock_value['stock_rate'],'high_rate'=>$high_price_rate,'chk_high_rate'=>"10",'disp_opt'=>$opt2,'stock_name'=>$jongmok_open.$chk_New_ico.$cur_stock_info['stock_name'],'stock_name_style'=>$stock_name_style);																																						
																																										 $rsa_high_price_rate_tag=get_thema_rate_tags($thema_Rate_Vals);

																																									   	 if($stock_value['stock_rate']>=15) {  # 1등주
																																																	
																																																	 $fst_stock_tags.=$rsa_high_price_rate_tag;
																																																	  $fst++;
																																																	   if($mode_fst==($ms-1))   $fst_stock_tags.= "</tr><tr>";																																																	
																																																	   $fst_disp=1;

																																																	
																																														 }

																																														else if ($stock_value['stock_rate']>=5 and $stock_value['stock_rate']<15 ) {  # 2등주
																																																	  $snd_stock_tags.=$rsa_high_price_rate_tag;
																																																	  $snd++;
																																																	  if($mode_snd==($ms-1))  $snd_stock_tags.= "</tr><tr>";
																																																	  $snd_disp=1;
																																																
																																														}																																																																																								  
																																																																																																							
																																														 else {																																																																																														 
																																																		 $trd_stock_tags.=$rsa_high_price_rate_tag;
																																																		 $trd++;
																																																		  if($mode_trd==($ms-1))  $trd_stock_tags.= "</tr><tr>";																																														 
																																																		  $trd_disp=1;
																																																																																																							
																																																	 }	

	}
																																																	 $fst_stock_tags.="</table>";
																																																	 $snd_stock_tags.="</table>";
																																																	 $trd_stock_tags.="</table>";

																																																$return_rel_stock_Rank_tags="<table width=100% border=0><tr><td><table style='border: 1px dashed orange; border-radius: 10px; background-color:white; border-spacing:3px;' width=98%> ";

																																																	  if($fst_disp)	$return_rel_stock_Rank_tags.="<tr><td><img src='../img/imoticon/num4/01.gif'></td><td>$fst_stock_tags</td></tr>";
																																																	
																																																		if($snd_disp)	$return_rel_stock_Rank_tags.="<tr>".$Rank_Vals['dot_line']."</tr><tr><td><img src='../img/imoticon/num4/02.gif'></td><td>$snd_stock_tags</td></tr><tr>".$Rank_Vals['dot_line']."</tr>";

																																																		if($trd_disp)	$return_rel_stock_Rank_tags.="<tr><td><img src='../img/imoticon/num4/03.gif'></td><td>$trd_stock_tags</td></tr>";
																																																	
																																																	$return_rel_stock_Rank_tags.="</table>																																									
																																																	
																																																	</td></tr>";

																																																	$return_rel_stock_Rank_tags.="<tr><td></td><td>".$get_scrap_grp."</td></tr>";

																																																	$return_rel_stock_Rank_tags.="<tr>$dot_line</tr></table>";

																																																	




 return $return_rel_stock_Rank_tags;

#################################################################
} # end of get_rel_stock_Rank
#################################################################


############################################
function get_thema_rate_tags($thema_Rate_Vals) {
###########################################
	   	   
#if(!$scrap_Vals['font-size']) $scrap_Vals['font-size']="12px";


if($thema_Rate_Vals['high_rate']>$thema_Rate_Vals['chk_high_rate']) {
		if($thema_Rate_Vals['finish_rate']!=$thema_Rate_Vals['high_rate']) $today_high_rate_tags="(".cur_deco_txt($thema_Rate_Vals['disp_opt'],$thema_Rate_Vals['high_rate'],$thema_Rate_Vals['chk_high_rate'],5,8).")";																																																			
		else $today_high_rate_tags="(★)";	
}


if($thema_Rate_Vals['no_tbl'])  $return_thema_rate_tags="<font style='".$thema_Rate_Vals['stock_name_style']."'>".$thema_Rate_Vals['stock_name']."</font>".cur_deco_txt($thema_Rate_Vals['disp_opt'],$thema_Rate_Vals['finish_rate'],$thema_Rate_Vals['chk_high_rate'],5,8)." $today_high_rate_tags";
else  $return_thema_rate_tags="<td  width=120px; style='".$thema_Rate_Vals['stock_name_style']."'>".$thema_Rate_Vals['stock_name']."</td><td nowrap >".cur_deco_txt($thema_Rate_Vals['disp_opt'],$thema_Rate_Vals['finish_rate'],$thema_Rate_Vals['chk_high_rate'],5,8)." $today_high_rate_tags</td>";
													
 return $return_thema_rate_tags;

#################################################################
} # end of get_rel_stock_tags
#################################################################



#################################################################
function daily_news_scrap_writE($connect) {
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$cur_year=date("Y");
$up_dir="./dta/news/$cur_year"; # 첨부파일을 업로드할 디렉토리 .. 년도별로 관리

$GR_Vals=Get_Vals('mode');

$tot_rel_num=12;

$test_on=0;


if($test_on) print_r($GR_Vals);


if($GR_Vals['type']=='del') {


				$query_del="delete from `tbl_news_scrap`    where no =".$GR_Vals['no']." " ;
 			   $result_del=mysqli_query($connect,$query_del); 

			   echo $query_del;

$go_back=1;


}

if($GR_Vals['type']=='update') {

				  if($GR_Vals['rel_news_title']) {

						 for($gr=0;$gr<count($GR_Vals['rel_news_title']);$gr++) {

							  if(empty($GR_Vals['rel_news_title'][$gr])) continue;

							 $rel_news_link.=addslashes($GR_Vals['rel_news_title'][$gr])."##^*^##".$GR_Vals['rel_news_url'][$gr]."##^*^##".trim($GR_Vals['rel_stock_code'][$gr])."@@@@@@@";
						 }

							  $rel_news_link=substr($rel_news_link,0,-7);

				  }


         if($GR_Vals['market']) $source_str="source='market_news',";

			$query_update="update `tbl_news_scrap` set  news_title='".addslashes($GR_Vals['news_title'])."', news_link='".$GR_Vals['news_link']."',  $source_str  rel_stock='".$GR_Vals['rel_stock']."',  rel_stock_catch_price='".$GR_Vals['rel_stock_catch_price']."', rel_thema='".$GR_Vals['rel_thema']."', news_cmt='".addslashes($GR_Vals['news_cmt'])."', rel_news_link='".$rel_news_link."', top_pick='".$GR_Vals['top_pick']."',  rtime='".$GR_Vals['rtime']."' ,  rel_no='".$GR_Vals['rel_no']."'  where no ='".$GR_Vals['no']."' " ;


 if($test_on) {
	    print_r($GR_Vals);
			  echo $query_update;

			   exit;
 } 
 
 else   		    $result_update=mysqli_query($connect,$query_update); 

$go_back=1;

}


if($test_on) exit;


if($go_back) {

					#	 echo "	  <meta http-equiv=\"refresh\" content=\"0;url=$cur_php?mode=daily_news_scrap_price_update\"> ";

echo "	  <meta http-equiv=\"refresh\" content=\"0;url=$cur_php?mode=dns_pu\"> ";

}

if($GR_Vals['rel_no'])  	$query_news="SELECT no,news_title,news_link,rtime FROM `tbl_news_scrap` where no =".$GR_Vals['no']." " ;
else 				$query_news="SELECT * FROM `tbl_news_scrap` where no =".$GR_Vals['no']." " ;

 			   $result_news=mysqli_query($connect,$query_news); 

     			$news_scrap=mysqli_fetch_array($result_news);


#  print_r($news_scrap);


echo "<html><body>";

echo ("
$style_css
										
												   
												   <script type=\"text/javascript\">

                                                     function     add_news_no(id_name,id_no) {

														 //alert(id_no);

											                          	self.close();

																	  opener.document.getElementById(id_name).value=id_no;
																		                                                                                                                                  

															}

															
                                                            function      submit_Confirm(v,chk_str) {		
																
																//alert(chk_str);
																																 //  폼으로 넘어온 변수 이름과 값을 확인

																																 if(0) {
																																	 
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}

                                                                                                                     chk_vals=document.getElementById(chk_str).value;


																												     stock_vals=document.getElementById('rel_stock_1').value;
																													 rtime_vals=document.getElementById('id_rtime').value;

																													 if(stock_vals=='종목명@') document.getElementById('rel_stock_1').value='';

																													// alert(document.getElementById('rel_stock').value);
																													// return;																												
																													

																										   if(chk_vals=='' || chk_vals=='타이틀을 적어주세요' || rtime_vals=='' ) { 
																											                                            alert('내용없음'); 
																										                                                return;
																																						}  // 제목이 없으면 등록 취소

																																					//alert(chk_vals);

																														

																										//  if (confirm(\"등록하시겠습니까?\")) {
																											  v.submit();
																												
																										//	} 
																							}


	                                         function      upload_file(up_dir,no) {
		                                       
												var Up_Dir = up_dir;
					                            var popup_X = event.screenX;	
												 var popup_Y = event.screenY;

												 var urls='$cur_php?mode=fuf&dir_st='+Up_Dir+'&no='+no+'';
											     var zz;

											     zz = window.open(urls, 'newWindow', 'width=500, height=500,left='+popup_X+',top='+popup_Y);

												  zz.focus();

											}


	                               function      upload_file_inner_html(tit,urls,no) {

									  // alert(tit);
									 //  return;


                                        var id_title='rel_news_title_'+no;
                                        var id_url='rel_news_url_'+no;

									   document.getElementById(id_title).value= tit;
   									   document.getElementById(id_url).value= urls;

										}



			</script>
											");
   
echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' > "; # start of 1번째  tbl

echo "<tr><td>";

																					echo     "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:14px; padding:0px;' border=0 > "; # start of 1번째  tbl


																					if($GR_Vals['rel_no'] and empty($news_scrap['rel_no']) ) { $mode_tags="<input type=hidden name=mode  value='daily_news_scrap_list'>"; 
																					                                         $del_tags="";
																															 $rel_no_notice="<font style='color:red;'>연관 테마글 등록 진행중입니다.</font>";
																															 
																					
																					}
																					else {   $mode_tags="<input type=hidden name=mode  value='daily_news_scrap_write'>";
																					             $del_tags="<a href=\"$cur_php?mode=daily_news_scrap_write&type=del&no='".$news_scrap['no']."' \"><img src='../img/memo_del.gif'></a>";
																								 $GR_Vals['rel_no']=$news_scrap['rel_no'];
																					}
																											
																																 
																				    echo     "
																																   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																	".$mode_tags."
																																	<input type=hidden name=type  value='update'>
																																	<input type=hidden name=no  value='".$news_scrap['no']."' >
																																	<input type=hidden name=rel_no  value=".$GR_Vals['rel_no'].">";

																																	 if($news_scrap['top_pick']) {   $chk_str[$news_scrap['top_pick']]="checked"; }

																																	  if($news_scrap['source']) $chk_source_str="checked";

																																	#  print_r($chk_str);



																																	echo "<tr><td colspan=3>".$news_scrap['rel_no']."<input type='radio'  name='top_pick'  value='1'  $chk_str[1]><img src='../img/tp01.png'> <input type='radio'  name='top_pick'  value='2' ".$chk_str[2].">	리포트
																																	 <input type='checkbox'  name='market'  value='1'  $chk_source_str>	마켓  ".$rel_no_notice."</td></tr>";


																											echo  "<tr><td>
																																			<img src='../img/micon1.gif'>뉴스 스크랩</a></td>
																																			
																																			<td> <input type='text' name=news_title  id='news_title' value=\"".str_replace("\"","&quot;",$news_scrap['news_title'])." \" size='60'  class=form_nc >

																																								   <input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_Confirm(document.myform,'news_title')\">																				
																																								   ".$del_tags."
																																								   <br>
																																								   <input type='text' name=news_link  id='news_link' value=\"".$news_scrap['news_link']."\" size='60'  class=form_nc >
																																								   

																																																							   </td>
																																																							   </tr>";



	 $rel_stock_array=explode('@@',$news_scrap['rel_stock_info']);
 	 $rel_stock_story_array=explode('@',$news_scrap['rel_stock_story']);

	 $stock_info_tags="<table style='font-size:15px;'>";
	 $n=0;
																																	 			foreach($rel_stock_array as $stock_key => $stock_value) { 																																										
																																																																	$stock_info_value= explode('#',$stock_value); 

																																																																	$stock_info_tags.="<tr>
																																																																	<Td>".$stock_info_value[1]."</td><td>".$stock_info_value[0]."</td>
																																																																	</tr>";

																																																																	

																																																																}

																																																																$stock_info_tags.="</table>";



																											echo "<tr><td><img src='../img/micon1.gif'> 연관주식 </tD><td><input type='text' name='rel_stock'    id='rel_stock_1' value='".$news_scrap['rel_stock']."' size='59'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																											<input type='text' name='rel_stock_catch_price'    id='rel_stock_catch_price' value='".$news_scrap['rel_stock_catch_price']."' size='6'  class=form_nc style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'></td></tr>";

																											echo "<tr><td></td><td>$stock_info_tags</td></tr>";

																											echo "<tr><td><img src='../img/micon1.gif'> 연관테마</td><td> 
																																	 <input type='text'   id='thema_no_1' value='".$news_scrap['rel_thema']."'  name='rel_thema' size='40'  class=form_nc  onclick=javascript:openclub2('$cur_php?mode=thema_manaGe&opt=popup&key_word=".$thema_name."&id_no=1','width=1000,height=1500','get_thema') style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																																	 <input type=hidden name='rel_thema_no' id='thema_name_1'>
																																	 <input type='text' name='rtime'  id='id_rtime' value='".$news_scrap['rtime']."' size='18'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																														 </td></tr>
																																	 ";

																																	 echo "<tr><td><img src='../img/micon1.gif'> 연관뉴스 </td><td><table>";

																																              	 $rel_news_link_array=explode('@@@@@@@',$news_scrap['rel_news_link']);
																																	 			foreach($rel_news_link_array as $rnl_key => $rnl_value) { 																																										
																																																																	$rel_news_link= explode('##^*^##',$rnl_value); 
																																																																	$rel_news_title[]=$rel_news_link[0];
																																																																	$rel_news_url[]=$rel_news_link[1];
																																																																	$rel_stock_code[]=$rel_news_link[2];
																																																																}

																																	 for($n=0;$n<$tot_rel_num;$n++) {
																														
																																													 echo "<Tr><td><input type='text' name='rel_news_title[]' id='rel_news_title_".$n."' value=\"".str_replace("\"","&quot;",$rel_news_title[$n])."\" size='30'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>";

																																													echo "	 <input type='text' name='rel_news_url[]' id='rel_news_url_".$n."' value='".$rel_news_url[$n]."' $auto_clear_tag size='10'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>";
																																													echo "	 <input type='text' name='rel_stock_code[]' id='rel_stock_code_".$n."' value='".$rel_stock_code[$n]."' $auto_clear_tag size='6'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>";
																																													echo " <input type=button value=\"첨부\"  class=form2 style='cursor:hand'  onclick=\"upload_file('".$up_dir."','".$n."')\">";


																																													echo "
																																																	   &nbsp; 
																																																 </td></<tr>";
																																											 }
																																	 
																																	 echo "</table></td></tr>";



																										echo "<tr><td><img src='../img/micon1.gif'> 코멘트</td><td> &nbsp;
																																	  <input type='text'  name='news_cmt'  value='".$news_scrap['news_cmt']."' size='68'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>																																	
																																	
																														 </td></tr>
																																	 ";

																
								
																											echo "</table></form>";
echo "</td></tr>";												
   echo  "</table> "; # start of 1번째  tbl
																					


echo "</body></html>";






#echo " window.onload = closeWindow(); ";

#################################################################
} # end of thema_list($connect)
#################################################################





#################################################################
function daily_news_scrap_grp($connect) {  # 뉴스와 관련된 테마주 그래프 업데이트
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$test_on=0;

$GR_Vals=Get_Vals('mode');

if($test_on)print_r($GR_Vals);


if($GR_Vals['mode_two']=="grp") {
$today= date("Y-m-d");     

 $skip_Array=array('mode_two');
 $qry_signal= make_qry($GR_Vals,$skip_Array,"skip_yes");

 $qry_scrap_news_grp="insert  into `tbl_news_scrap_grp` set   ".$qry_signal.", uDate='".$today."'    ";

if($test_on)print_r($qry_scrap_news_grp);
else  $result=mysqli_query($connect,$qry_scrap_news_grp); 

if($result) {
				  if(!$test_on)  {		 Header("Location:$cur_php?mode=dnsl");
					exit;                                    
				  }
  }
exit;

}


elseif($GR_Vals['mode_two']=="write") {

   echo $style_css;

										echo "
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

										echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:15px;' width=100% align=center border=0> "; # start of 1번째  tbl
										echo "<tr><td><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
																																																					<input type='hidden' name=mode value='daily_news_scrap_grp'>
																																																					<input type='hidden' name=mode_two value='grp'>	
																																																					<input type='hidden' name=thema_no value=".$GR_Vals['thema_no'].">	
																																																					<input type='hidden' name=news_no value=".$GR_Vals['news_no'].">	
												 </td></tr>";

												 echo "<Tr><td>
												 <textarea name='cmt' style=\"width:90%; height:100px; overflow-x:hidden; overflow-y:auto;font-size:15px; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;border-radius: 10px;\" class=form_nc ></textarea>
												 </td></tr>";

										echo "<tr><td >";

														# 시작 :스마트 에디터 불러오기
														echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
														echo"<textarea name=contents id=\"ir1\" style=\"width:99%; height:1000px;display:none;\">".$cmt['contents']."</textarea>";

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

	                                	echo "<tr><td align=center> <input type=button value='등 록' onclick=\"javascript:submit_Confirm(document.myform);\" style='width:150px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'> </td> </tr>";
										
										echo  "</table> "; # start of 1번째  tbl


}



#################################################################
} # end of daily_news_scrap_grp($connect)  # 뉴스와 관련된 테마주 그래프 업데이트
#################################################################



############################################
function Thema_manaGe($connect) {  # D4:: ★★★★★★★★ 테마 팝업창, 테마 일별로 보여주기
###########################################
global $admin_info;
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');
$G_Stock_Code=$GR_Vals['stock_code'];

$opt=$GR_Vals['opt'];

$chk_high_vals=9;
$today_high_rate=10;

#변수정의

# tbl_thema_name 삭제
# tbl_daily_thema_stock     UPDATE `tbl_daily_thema_stock` set thema_no='173' WHERE thema_no='98'
# tbl_thema_story  UPDATE `tbl_daily_thema_story` set thema_no='173' WHERE thema_no='98'
# tbl_event_Diary
# tbl_news_scrap UPDATE `tbl_news_scrap` set thema_no='173' WHERE thema_no='98'


$tt=0;


## 오늘 매매체크버튼 , 주말에 활용
if($GR_Vals['rt']=='on') 	      { setcookie('opt[rt]',1,time()+12800,'/');  $admin_info['rt']=1; }
elseif($GR_Vals['rt']=='off')  { setcookie('opt[rt]',1,time()-3600,'/'); $admin_info['rt']=0; }


if($admin_info['rt']) $rt_tag="<img src='../img/check_on.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=thema_manaGe&key_word=".$GR_Vals['key_word']."&rt=off'\" style='cursor:hand;'>장중시세 ";
else  $rt_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=thema_manaGe&key_word=".$GR_Vals['key_word']."&rt=on'\" style='cursor:hand;'>장마감";



# 테마등록

      $update_tags=" <tr><td colspan=10 class=n1s>
	                                 <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
                               	   <input type='hidden'  name=mode  value='thema_manaGe'>
                               	   <input type='hidden'  name=mode_two  value='insert'>
								   <input type='hidden'  name=opt  value='".$GR_Vals['opt']."'>
									<input type='hidden'  name=id_no  value='".$GR_Vals['id_no']."'>
									<input type='hidden'  name=thema_no  value='".$GR_Vals['thema_no']."'>
                                테마 신규등록  :::: <input type='text'  size='14'  name=thema_name class=form_nc style='cursor:hand'  value='".$GR_Vals['key_word']."'>
							 	핀업No<input type='text'  size='14'  name=finup_no class=form_nc style='cursor:hand'>
								<input type=submit value='등 록' class=form_nc style='width:40px;'></form>
								";

# 테마검색
       $search_tags=" 

								<tr><td colspan=10 class=n1s>
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform3>
											<input type='hidden'  name=mode  value='thema_manaGe'>
											 <input type='hidden'  name=opt  value='".$GR_Vals['opt']."'>
											<input type='hidden'  name=id_no  value='".$GR_Vals['id_no']."'>
											<input type='hidden'  name=type  value='".$GR_Vals['type']."'>
											테마 검색 :::: <input type='text'  size='24'  name='key_word' class=form_nc style='cursor:hand' value='".$GR_Vals['key_word']."' $auto_clear_tag>
											<input type=submit value='검색' class=form_nc style='width:40px;'>
											$rt_tag
									</form>";


# 시작 : 테마네임이 있다면 신규 등록해줄것
						if($GR_Vals['thema_name'] && $GR_Vals['mode_two']=='insert') { # 테마를 신규로 등록

												   $today = date("Y-m-d");

   $query_update="update tbl_thema_name set thema_name=".trim($GR_Vals['thema_name']).",finup_no=".trim($GR_Vals['finup_no'])." where thema_no= ".$GR_Vals['thema_no']." ";

												   if($GR_Vals['thema_no']) 		  $query_ins="update tbl_thema_name set  finup_no=".trim($GR_Vals['finup_no'])." where thema_no= ".$GR_Vals['thema_no']." ";

												   else 												$query_ins="insert into tbl_thema_name set thema_name='".$GR_Vals['thema_name']."',finup_no='".$GR_Vals['finup_no']."',uDate='$today'";
													$result_ins=mysqli_query($connect,$query_ins); 

						}
# 끝 : 테마네임이 있다면 등록해줄것


# 현재종목의 현재가를 가져옴

          # 시작 :전체 종목 명 가져오기
																										   $arr_stock_srch['qry']="select stock_code,stock_name,stock_rate,stock_rate_rt,stock_yrate,stock_price,stock_high_price from all_stock_info";																											
																										   $arr_stock_srch['keys'] ='stock_code';
																										  # $arr_stock_srch['multi_keys'] =0; 
																											$stock_srch_array=php_mysql_Query($arr_stock_srch,$connect);
                                                                                                          # 종목 네임 배열
																										 $all_stock_name=$stock_srch_array['multi_keys'];

																										# print_r($all_stock_name);





# 시작 :키워드가 있다면.. 검색해줄것

					if($GR_Vals['key_word']) {

																	$thema_tags.="<tr><td colspan=10><table border=0  cellspacing=\"4\" cellpadding=\"4\" width=100%>";

											$query_thema_search="select * from tbl_thema_name  where thema_name like '%".$GR_Vals['key_word']."%'";
											$result_thema_search=mysqli_query($connect,$query_thema_search); 

					  											#echo  $query_thema_search;

										   while($thema_search = mysqli_fetch_array($result_thema_search)){

											   if($GR_Vals['opt']=='popup') $add_thema_tags="<a onclick=\"javascript:add_thema_info('".$GR_Vals['id_no']."','".$thema_search['thema_no']."','".$thema_search['thema_name']."','".$GR_Vals['type']."');\"  style='cursor:hand'>";
																																											else $add_thema_tags="";

												$thema_tags.="<tr><td colspan=2><table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:14px;line-height:200%' width=100%>";


												# d4 상단 테마 타이틀
												$thema_tags.="<tr style='border: 1px dashed orange; border-radius: 10px; background-color:yellow;' height='45px;'><td  colspan=2 align=center> <font style='font-size:25px;font-weight:bold;'>".$add_thema_tags.$thema_search['thema_name']."</font> (".$thema_search['uDate'].")</td></tr>";
												
												$arr_qry['qry']="select stock_code,stock_price,stock_yrate,stock_high_price,stock_rate,rel_news_no,chk_New,uDate from tbl_daily_thema_stock where thema_no='".$thema_search['thema_no']."' order by uDate desc,stock_rate desc";
												$arr_qry['keys']='uDate';
												$arr_qry['multi_keys']=1;
												$rel_stock_array=php_mysql_Query($arr_qry,$connect);
												
												# 테마 번호로 thema_stock 에서 일별로 종목을 그룹화 하고, 이를 상승률별로 나눔
												#############################################################


												if($rel_stock_array['multi_keys']) {

													

													                                                                              $opt2['type']=21;
																																  $opt2['font']="14px";
																																  $opt2['str']="%";
																																  $thema_stock_tags="";

																																$thema_tags.="<tr><td colspan=2> 
																																 <table style='font-size:14px;line-height:200%' width=100%>";
																																 
																																						 foreach($rel_stock_array['multi_keys'] as $rsa_Date => $date_stock_Array) { # start of Date																																						     


																																							 
																																							 			$rel_stock_tags="";
																																										$fst_stock_tags="";
																																										$snd_stock_tags="";
																																										$trd_stock_tags="";
																																										$fst=0;
																																										$snd=0;
																																										$trd=0;
																																										$fst_disp=0;
																																										$snd_disp=0;
																																										$trd_disp=0;
																																										$today_high_stock_name_ins="";
																																										$get_scrap_news="";

																																										
																																										

																																							   $scrap_news_no=$date_stock_Array[0]['rel_news_no'];

																																							   $base_Day=calender_str(3,13,$rsa_Date);

																																							
																																							 if($GR_Vals['opt']=='popup') { $max_grp_width="700px;";  $mn=3; $short_disp=1; }
																																							 else { $max_grp_width="600px;"; $mn=2; }

																																							  $scrap_Vals=array('no'=>$scrap_news_no,'max_width'=>$max_grp_width,'disp'=>"scrap_news",'font-size'=>"15px;");
																																							  $get_scrap_grp=get_scrap_grp($scrap_Vals,$connect);

																																							  $news_Vals=array('no'=>$scrap_news_no,'disp'=>"scrap_news",'font-style'=>"font-size:13px;color:blue;font-weight:bold;",'target'=>"pop",);
																																							  $get_scrap_news=get_scrap_news($news_Vals,$connect);

																																																																												    
																																								  $fst_stock_tags="<table style='font-size:12px;' border=0><tr>";
																																								  $snd_stock_tags="<table style='font-size:12px;' border=0><tr>";
																																								  $trd_stock_tags="<table style='font-size:12px;'><tr>";

																																								  $high_stock_font="<font style='font-weight:bold;'>";


																																							foreach($date_stock_Array as $rsa_key => $rsa_stock_value)    { # start of rsa_stock_value

																																								       $chk_New_bg="";
																																									   $chk_New_ico="";
																																									 #  $rsa_high_price_rate_tag="";
																																									   $rsa_high_price_rate="";
																																								    	
																																										$mode_fst=$fst%$mn;			
																																										$mode_snd=$snd%$mn;			
																																										$mode_trd=$trd%$mn;			

																																										$cur_stock_info=$all_stock_name[$rsa_stock_value['stock_code']]; # 현재종목에 대한 리스트																																										 
																																																																																				
																																										if($cur_stock_info['stock_price']) { 																																																																																						

																																												# 만약 장중이라면
																																											if($admin_info['rt']) $today_high_price_rate= $cur_stock_info['stock_rate_rt'];																																										
																																											else $today_high_price_rate=  round(($cur_stock_info['stock_high_price']/($cur_stock_info['stock_price']-$cur_stock_info['stock_yrate'])-1)*100,2); 
																																										
																																										}

																																										
																																										 
																																										#당일 종목중 고가가 10% 이상인 종목과 그이하인 종목으로 구분해야함.
																																								   	    # 테마별로 나눠서, 검색어별로 표시

																																										$today_high_stock_name_ins.=$cur_stock_info['stock_name']."@";

																																										if($today_high_price_rate>=$today_high_rate) {

																																										    $today_high_stock_name[$thema_search['thema_no']][$cur_stock_info['stock_code']]=$cur_stock_info['stock_name'];
																																											$today_high_stock_rate[$thema_search['thema_no']][$cur_stock_info['stock_code']]=$today_high_price_rate; 
																																										
																																											$today_high_stock_count[$cur_stock_info['stock_code']][]=$today_high_price_rate;
																																											$today_stock_high_rate[$cur_stock_info['stock_code']][]=$cur_stock_info['stock_rate']; 
																																																																																						
																																										}

																																										else {  #당일 10% 이하인 종목들.. 보여줄 필요가 잇을까?																																										

																																											$today_low_stock_name[$thema_search['thema_no']][$cur_stock_info['stock_code']]=$cur_stock_info['stock_name'];
																																											$today_low_stock_rate[$thema_search['thema_no']][$cur_stock_info['stock_code']]=$today_high_price_rate; 
																																										
																																											$today_low_stock_count[$cur_stock_info['stock_code']][]=$today_high_price_rate;
																																											$today_stock_low_finish_rate[$cur_stock_info['stock_code']][]=$cur_stock_info['stock_rate']; 																																											

																																										}


																																										# 당일 최고가가 DB에 등록되어 잇다면.. 체크

																																																																																				   
																																										if($rsa_stock_value['stock_high_price']) { 																																											 
																																											 $rsa_high_price_rate= round(($rsa_stock_value['stock_high_price']/($rsa_stock_value['stock_price']-$rsa_stock_value['stock_yrate'])-1)*100,2);																																											 
																																											 if($admin_info['rt']) $rsa_high_price_rate= $cur_stock_info['stock_rate_rt'];																																										


																																										} 

																																										if($rsa_stock_value['chk_New']) { $chk_New_bg="background-color:red;color:white;";  # 신규종목은 노란색배경
																																										#$chk_New_ico="<img src='../img/n2.gif'>";

																																										}

																																										if($rsa_stock_value['stock_rate']>=15) $stock_name_style="font-weight:bold;font-size:14px;".$chk_New_bg; 
																																										elseif($rsa_stock_value['stock_rate']<5)  $stock_name_style="color:#A4A4A4;".$chk_New_bg; 
																																										else $stock_name_style=$chk_New_bg;


																																										$thema_Rate_Vals=array('finish_rate'=>$rsa_stock_value['stock_rate'],'high_rate'=>$rsa_high_price_rate,'chk_high_rate'=>"15",'disp_opt'=>$opt2,'stock_name'=>$chk_New_ico.$cur_stock_info['stock_name'],'stock_name_style'=>$stock_name_style);													
																																										
																																										
																																										 $rsa_high_price_rate_tag=get_thema_rate_tags($thema_Rate_Vals);
																																																																																												 
																																														 if($rsa_stock_value['stock_rate']>=15) {  # 1등주
																																																	
																																																	$fst_stock_tags.=$rsa_high_price_rate_tag;

																																																	  $fst++;
																																																	   if($mode_fst==($mn-1))   $fst_stock_tags.= "</tr><tr>";																																																	
																																																	   $fst_disp=1;
																																														 }

																																														else if ($rsa_stock_value['stock_rate']>=5 and $rsa_stock_value['stock_rate']<15 ) {  # 2등주
																																																	  $snd_stock_tags.=$rsa_high_price_rate_tag;
																																																	  $snd++;
																																																	  if($mode_snd==($mn-1))  $snd_stock_tags.= "</tr><tr>";
																																																	  $snd_disp=1;
																																														}																																																																																								  
																																																																																																							
																																														 else {																																																																																														 
																																																		 $trd_stock_tags.=$rsa_high_price_rate_tag;
																																																		 $trd++;
																																																		  if($mode_trd==($mn-1))  $trd_stock_tags.= "</tr><tr>";																																														 
																																																		  $trd_disp=1;
																																																																																																							
																																																	 }																																																																																					  
																																																	
																																																	
																																											} # end of   of rsa_stock_value


																																																	 $fst_stock_tags.="</table>";
																																																	 $snd_stock_tags.="</table>";
																																																	 $trd_stock_tags.="</table>";

																																																	 $today_high_stock_name_ins= substr($today_high_stock_name_ins,0,-1);	

																																																	 if($GR_Vals['opt']=='popup') { $add_stock_tags="<a onclick=\"add_stock_info('".$GR_Vals['id_no']."','".$today_high_stock_name_ins."','".$GR_Vals['type']."');\" style='cursor:hand;'>";
																																																	 $go_to_list="";
																																																	                                 
																																																	 }
																																																	else  { $add_stock_tags=""; 
																																																	             $go_to_list="<a href=$cur_php?mode=dnsl&uDate=".$rsa_Date."&no=".$rsa_stock_value['rel_news_no']." target='news_d2'>"; }


																																																	 $thema_stock_tags.="<tr><td nowrap>".$go_to_list.$add_stock_tags.$base_Day['unix_str']."</td><td>
																																																	<table width=98%>";

																																																	

																																																	 $thema_stock_tags.="<tr><td><img src='../img/bul59.gif'></td><td>".$get_scrap_news."</td></tr><tr>$dot_line</tr>";

																																																	 if($fst_disp)	$thema_stock_tags.="<tr><td><img src='../img/imoticon/num4/01.gif'></td><td>$fst_stock_tags</td></tr><tr>$dot_line</tr>";
																																																	
																																																		if($snd_disp)	$thema_stock_tags.="<tr><td><img src='../img/imoticon/num4/02.gif'></td><td>$snd_stock_tags</td></tr><tr>$dot_line</tr>";

																																																		if($trd_disp)	$thema_stock_tags.="<tr><td><img src='../img/imoticon/num4/03.gif'></td><td>$trd_stock_tags</td></tr>";
																																																	
																																																	$thema_stock_tags.="</table>																																									
																																																	
																																																	</td></tr>";

																																																	$thema_stock_tags.="<tr><td></td><td>".$get_scrap_grp."</td></tr>";

																																																	$thema_stock_tags.="<tr>$dot_line</tr>";



																																						 } # end of Date
																															   



																															   #  해당 테마와 관련된 종목
																																	 
																																if($today_high_stock_name[$thema_search['thema_no']]) {
																																	 $nn=0;

																																	 foreach($today_high_stock_name[$thema_search['thema_no']] as $hsn_key => $hsn_value) { 
																																		  
																																		  $today_high_stock_name_tags[$thema_search['thema_no']].= $hsn_value."@";

																																		
																																		  # 하단에 표시
																																		  $today_high_stock_rate_tags[$thema_search['thema_no']].= $hsn_value.cur_deco_txt($opt2,$today_high_stock_rate[$thema_search['thema_no']][$hsn_key],$today_high_rate,5,8)." ";

																																		  $mode_no=$nn%$mn;

																																		  $nn++;

																																		     if($mode_no==($mn-1))    $today_high_stock_rate_tags[$thema_search['thema_no']].="<br>";
																																	  }


																																}

																																## 해당테마와 관련된 종목



																																   $rn=0;
																																   $ln=0;

																																   $wth_high="25%";	
																																   

																																   # 상단에 전체 테마와 관련된 종목중 당일 %이상인 종목

																																   if($today_high_stock_count) {																																  

																																	   $high_stock_num_tags="<tr><td colspan=10 align=center width=100%><table style='font-size:14px;line-height:200%;background-color:yellow;' width=98% border=0><tr><td align=center width=100px;>전체 테마<br>당일 최고가<bR> ".$today_high_rate."%이상</td><td >";																																  
																																	   $high_stock_num_tags.="<table style='font-size:14px;' align=left border=0><tr>";

																																
																																														foreach($today_high_stock_count as $s_code => $s_cnt) { 
																																															
																																															 if($GR_Vals['opt']!='popup') $click_tasgs="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10000&stock_code=".$s_code."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>";


																																															$thema_Rate_Vals=array('finish_rate'=>$today_stock_high_rate[$s_code][0],'high_rate'=>$today_high_stock_count[$s_code][0],'chk_high_rate'=>$today_high_rate,'disp_opt'=>$opt2,'stock_name'=>$click_tasgs.$all_stock_name[$s_code]['stock_name']);																																							
																																															$get_thema_rate_tags=get_thema_rate_tags($thema_Rate_Vals);


																																															  $high_stock_num_tags.= "<td width='13px;' style='font-size:12px;' align=right>(".count($s_cnt).")</td>".$get_thema_rate_tags;

																																															   $mode_no=$rn%($mn);																																															
																																																 if($mode_no==($mn-1))    $high_stock_num_tags.="</tr><tr>";
																																																   $rn++;		
																																														}
																																														$high_stock_num_tags.="</tr></table>";
																																														$high_stock_num_tags.="</td></tr></table></td></tr>";
																																	}

																																	   if($today_low_stock_count) {

																																	      $low_stock_num_tags="<tr><td colspan=10 align=center width=100%><table style='font-size:14px;background-color:#F2F2F2;line-height:200%'  width=98%><tr><td style='background-color:#F2F2F2;' align=center width=100px;>".$today_high_rate."%이하</td><td >";		
																																		  $low_stock_num_tags.="<table style='font-size:12px;background-color:#F2F2F2;' ><tr>";

																																	     # arsort($today_low_stock_count);
																																														
																																														foreach($today_low_stock_count as $s_code => $s_cnt) { 

																																															 $stock_name_style="color:#A4A4A4;"; 
																																															 	$thema_Rate_Vals=array('finish_rate'=>$today_stock_low_finish_rate[$s_code][0],'high_rate'=>$today_high_stock_count[$s_code][0],'chk_high_rate'=>$today_high_rate,'disp_opt'=>$opt2,'stock_name'=>$all_stock_name[$s_code]['stock_name'],'stock_name_style'=>$stock_name_style);																																							

																																														   	$get_thema_rate_tags=get_thema_rate_tags($thema_Rate_Vals);																																															

																																															  $low_stock_num_tags.= "<td width=13px style='font-size:12px;' align=right>(".count($s_cnt).")</td>".$get_thema_rate_tags;

																																															   $mode_no=$ln%($mn);


																																																 if($mode_no==($mn-1))    $low_stock_num_tags.="</tr><tr>";
																																																 		  $ln++;		
																																														}
																																		 $low_stock_num_tags.="</tr></table>";

																																		  $low_stock_num_tags.="</td></tr></table></td></tr>";

																																	}


																																	

																																	 $insert_stock_name_tags= substr($today_high_stock_name_tags[$thema_search['thema_no']],0,-1);	
																																	 
																																	 if($GR_Vals['opt']=='popup' and $rn>0) $add_stock_ins_tags="<a onclick=\"add_stock_info('".$GR_Vals['id_no']."','". $insert_stock_name_tags."','".$GR_Vals['type']."');\" style='cursor:hand;'>";
																																											else $add_stock_ins_tags="";

																																	$thema_tags.="<tr><td style='background-color:yellow;' align=center>".$add_stock_ins_tags.$today_high_rate."%이상 상승<br>최고가</Td><td style='font-size:15px;'>".$today_high_stock_rate_tags[$thema_search['thema_no']]."</td></tr><tr>".$dot_line."</tr>";
																																	$thema_tags.=$thema_stock_tags;





																																$thema_tags.="</table></td></tr>";


												}

																								$thema_tags.="</table>";						    







										   }


												$thema_tags.="</table></td></tr>";
						   
					}

# 끝 :키워드가 있다면.. 검색해줄것


								
						$query_thema="select * from tbl_thema_name ";
						$result_thema=mysqli_query($connect,$query_thema); 

    
	       $thema_list_tags="<tr>";
             
			 while($thema_info = mysqli_fetch_array($result_thema)){

				

         //           $thema_tags.="<tr><td>no</td><td>테마이름</td><td>Date</td></tr>";

		            $nn=$tt%40;

		            if($nn==0)   $thema_list_tags.="<td valign=top>   <table border=0 class=n1s cellspacing=\"4\" cellpadding=\"4\" width=100%> <tr class=tt7><td width=170>테마이름</td></tr>";

                        $popup_link="<a href='$cur_php?mode=thema_manaGe&thema_no=".$thema_info['thema_no']."&key_word=".$thema_info['thema_name']."&id_no=".$GR_Vals['id_no']."&type=".$GR_Vals['type']."&opt=".$GR_Vals['opt']."'>";

				 
                if($thema_info['finup_no']) $finup_tags=" <font style='color:red;font-weight:bold;'>(F)</font>"; else $finup_tags="";

		 		    $thema_list_tags.="<tr class=tt8><td>".$popup_link.$thema_info['thema_name'].$finup_tags."</td></tr>";

		            if($nn==39)   $thema_list_tags.="</table></td>";

                      $tt++;
				 
				}

				#add_thema_info('".$GR_Vals['id_no']."','".$thema_info['thema_no']."','".$thema_info['thema_name']."');

				    $thema_list_tags.="</tr></table>";



					echo "<html><body>$search_tags";

echo $style_css;



echo ("
			<script type=\"text/javascript\">

                                                     function      add_thema_info(id_no,t_no,t_name,type) {

																			
																									 
																									//	  alert(t_name);
																									//	  alert(id_no);
																				 
																						//   opener.document.getElementById('open_num').value*1+1;															   															        

																						//   opener.document.getElementById('open_num').value=get_open_num;															   															        

																	 
																					   t_name_str_id= 'thema_name_'+id_no;
																					   ori_name_value=	opener.document.getElementById(t_name_str_id).value;


																					     t_no_str_id= 'thema_no_'+id_no;


																					
																					   ori_t_no=opener.document.getElementById(t_no_str_id).value;

																					  


                                                                     if(type=='pax' || ori_t_no =='' ) {																						 
																					   opener.document.getElementById(t_name_str_id).value=t_name; 
																					   opener.document.getElementById(t_no_str_id).value=t_no;
																		 
																			}

																	 else   {
																					 opener.document.getElementById(t_name_str_id).value=ori_name_value+'@@'+t_name;
																					 opener.document.getElementById(t_no_str_id).value=ori_t_no+'@@'+t_no;

																			}
                                                                    
																					   //  const hidden_id = opener.document.getElementById(btn_str_id);
																							//  alert(hidden_id);
																						//  hidden_id.style.display=\"block\"; // 표시
																						//    hidden_id.style.visibility ='visible';   // 표시

																							self.close();
								
															}


															       function      add_stock_info(id_no,rel_stock_list,type) {

																	                            	rel_stock_id= 'rel_stock_'+id_no;																			

																									     if(type=='pax'  ) {	

																											   ori_rel_stock_list=opener.document.getElementById(rel_stock_id).value;

																											 //  alert(ori_rel_stock_txt);

																											   opener.document.getElementById(rel_stock_id).value= ori_rel_stock_list+'@'+rel_stock_list;															   															        

																													}
                                                                                      else {
																												  opener.document.getElementById(rel_stock_id).value=rel_stock_list;															   															        
																										}

																	 
																					   


                                                                

															}




			</script>


											");


#//

echo "<table  border=0 cellspacing=\"0\" cellpadding=\"0\" width=100%>";

echo $high_stock_num_tags;
echo $low_stock_num_tags;

echo      $thema_tags;



if($GR_Vals['opt']) { echo $thema_list_tags;

echo $update_tags;
}

echo "</table>";



echo  "</body></html>";


exit;
		
 ################### end of  Thema_manaGe($connect) #######################
}
################### end of  Thema_manaGe($connect) #######################




#################################################################
function daily_news_scrap_price_update($connect)  {
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$GR_Vals=Get_Vals('mode');

$test_on=0;



if($test_on) print_r($GR_Vals);

                     # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기


$today= date("Y-m-d");     
if($GR_Vals['uDate']) $today=$GR_Vals['uDate'];


$utime= date("Y-m-d H:i:s");

# 오늘 등록된 뉴스의 관련 종목들을 전체 다 가져와서 새로 업데이트 함.


																						$query_news['qry']=" SELECT * FROM `tbl_news_scrap`  where  DATE_FORMAT(uDate,'%Y-%m-%d')='".$today."'  ";
																						$result_news=php_mysql_Query($query_news,$connect); 

																						#echo $query_news['qry'];

																						   $query_del['qry']="delete from tbl_daily_thema_stock    where uDate='".$today."'   ";
																						   $query_del['result']=1;
																							 if($test_on) { print_r($query_del); echo "<Br>\n"; }
																							  else { php_mysql_Query($query_del,$connect); 
																							  			   table_auto_increment('tbl_daily_thema_stock',$connect);

																							  }


																							  $query_del['qry']="delete from tbl_daily_thema_story   where uDate='".$today."'   ";
																						      $query_del['result']=1;
																							 if($test_on) { print_r($query_del); echo "<Br>\n"; }
																							  else{  php_mysql_Query($query_del,$connect); 
																										  table_auto_increment('tbl_daily_thema_story',$connect);
																							  }



																			       			foreach($result_news['value'] as $k_no => $k_value){

																							                            # 당일 기사에 있는 관련 종목을 가져와서 시세 데이터 업데이트 해줌


																							                           #  $new_ins_str="";
																														# $sort_stock_str="";

																														 $new_ins_str = array();
																														 $sort_stock_str=array();

																														 $thema_info_array=explode('@',$k_value['rel_thema']);


																																							   for($tsi=0;$tsi<count($thema_info_array);$tsi++) {

																																								              if($thema_info_array[$tsi]>0) {
																																														  $query_t_ins['qry']= "insert into tbl_daily_thema_story set  thema_no='".$thema_info_array[$tsi]."',  thema_story='".addslashes($k_value['news_title'])."',   uDate='".$today."'";
																																														  $query_t_ins['result']=1;
																																														  if($test_on) {print_r($query_t_ins); echo "<Br>\n"; }
																																														  else php_mysql_Query($query_t_ins,$connect); 
																																											  }

																																									  }

																														 
																														if($k_value['rel_stock']) {

																														            	$stock_info_array=explode('@',$k_value['rel_stock']);
																																		$ins_stock_qry="";																																		
																																		$rel_stock_str="";
																																		
																																	
																																		
																																												  for($si=0;$si<count($stock_info_array);$si++) {

																																													 
																																													 

																																																																					   $query_ts_srch="select * from  all_stock_info where stock_name='".$stock_info_array[$si]."'   ";
																																																																					   $result_ts_srch=mysqli_query($connect,$query_ts_srch); 
																																																																					   $ts_search = mysqli_fetch_array($result_ts_srch);

																																																																							if($ts_search) {

																																																																								                       $chk_New_Vals=0;
																																																																																	$base_stock_code[$si]=$ts_search['stock_code'];

																																																																																	 $stock_high_rate=  round(($ts_search['stock_high_price']/($ts_search['stock_price']-$ts_search['stock_yrate'])-1)*100,2);

																																																																																	 $stock_info_str=$ts_search['stock_name']."#".$ts_search['stock_code']."#".$ts_search['stock_rate']."#".$stock_high_rate."#".$ts_search['stock_vol']."#".$ts_search['stock_vol_cap'];

																																																																																	 $new_ins_str[$ts_search['stock_rate']][]=$stock_info_str;

																																																																																	 $sort_stock_str[$ts_search['stock_rate']][]=$stock_info_array[$si];

																																																																																	 #기존에 테마에 등록된 종목인지 체크한후 새로운 테마주면 chk_New에 표시해줘야함

																																																																																	  if($k_value['thema_no']>0){ 
																																																																																		  $query_chk="select count(*) from  tbl_daily_thema_stock  where stock_code='".$ts_search['stock_code']."'  and  thema_no='".$k_value['thema_no']."' ";
																																																																					                                                      $result_chk=mysqli_query($connect,$query_chk); 
																																																																																		  $chk_Arr = mysqli_fetch_array($result_chk);
																																																																																		  if(empty($chk_Arr[0])) $chk_New_Vals=1;	  
																																																																																	  } 

																																																																																	  

																																																																																	  echo $k_value['thema_no'].$chk_New_Vals;
																																																																																	  print_r($chk_Arr);

																																																																																	  #기존에 테마에 등록된 종목인지 체크한후 새로운 테마주면 chk_New에 표시해줘야함
																																																																																	 																																																																																	 
																																																																																	 $query_ins['qry']= "insert into tbl_daily_thema_stock set  stock_code='".$ts_search['stock_code']."', stock_price='".$ts_search['stock_price']."',stock_yrate='".$ts_search['stock_yrate']."', stock_high_price='".$ts_search['stock_high_price']."',   stock_cap='".$ts_search['stock_cap']."',   stock_vol='".$ts_search['stock_vol']."',   stock_vol_cap='".$ts_search['stock_vol_cap']."',   stock_rate='".$ts_search['stock_rate']."', rel_news_no='".$k_value['no']."',  chk_New='".$chk_New_Vals."', thema_no='".$thema_info_array[0]."', uDate='".$today."'";
																																																																																	  $query_ins['result']=1;

																																																																																	  if($test_on) { print_r($query_ins); echo "<Br>\n"; }
																																																																																	  else php_mysql_Query($query_ins,$connect); 

																																																																															}

																																																																				  }																																																																			


																																																																				  krsort($new_ins_str);																																																																				  
																																																																				  krsort($sort_stock_str);


																																																																				  foreach($new_ins_str as $ni_key => $ni_value) {



																																																																					   foreach($ni_value as $nis_key => $nis_value) {
																																																																					      $ins_stock_qry.=$nis_value."@@";
																																																																						  $ins_stock_story_qry.=$new_ins_story_str[$ni_key][$nis_key]."@";
																																																																						  $rel_stock_str.=$sort_stock_str[$ni_key][$nis_key]."@";
																																																																					   }

																																																																				  }

																																																																				  $ins_stock_qry=substr($ins_stock_qry,0,-2);
																																																																				  $ins_rel_stock_qry=substr($rel_stock_str,0,-1);
																																																																				 
																																																																				 $query_up['qry']="update  tbl_news_scrap set stock_code='".$base_stock_code[0]."',rel_stock='".$ins_rel_stock_qry."', rel_stock_info='".$ins_stock_qry."',  thema_no='".$thema_info_array[0]."',rel_utime='".$utime."'   where no='".$k_value['no']."'   ";
																																																																				 $query_up['result']=1;

																																																																				 if($test_on) {  echo "<font color=red>";  print_r($query_up); echo "</font><Br>\n"; }
																																																																			      else php_mysql_Query($query_up,$connect); 																																																																				  



																																																																				  

																														} # end of $k_value['rel_stock']



																						} # end of foreach






if($test_on)  exit;


else {

	
	echo "
   <script type=\"text/javascript\">

			function     reload_pop() {  // 	

			                                                              news_d2  ='$cur_php?mode=dnsl';																								
																																	window.open(news_d2, 'news_d2');  																																	
																																	parent.news_d3.location.reload(true);

														}

</script>

";

echo "<BODY onLoad='javascript: reload_pop();' >	";
						 

	

}


exit;

#histroy.go(-1);
#
#exit;
#									<BODY onLoad='javascript:alert(1);histroy.back();' >			

if(!$err_code) {

#    prj_analysis 에도 데이터 반영할 것
                 

							

													

} else   echo " 오류가 발생했습니다.";

#################################################################
} # all_stock_update ($adminID,$upfile,$connect)
#################################################################


#################################################################
function stock_all_history($connect) {  # D4 :: ★★★★★★★★ 개별종목 뉴스,테마, 거래현황
#################################################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;

$GR_Vals=Get_Vals('mode');

$max_width=$tbl_width['i4t']*0.97;

$opt_deco['type']=21;
$opt_deco['str']="%";
$opt_deco['font']="17px;";

$opt_float['type']=22;
$opt_rank['type']=31;

//if($GR_Vals['stock_name']) { 
//	$stock_name_info=get_stock_name_info($GR_Vals['stock_name'],$connect);
//    $GR_Vals['stock_code']=	$stock_name_info['stock_code'];
//}



$srch_get_array=array('urls'=>"$cur_php?mode=stock_all&stock_code=", 'limit_no'=> '14', 'double_target'=>'news_d2','opt'=>'stock');
$srch_history_tags=search_history($srch_get_array,$connect);

#주식정보
$stock_info= get_stock_info($GR_Vals['stock_code'],$connect);


#테마정보
   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no'; 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
																											$all_thema_name=$thema_srch_array['multi_keys'];

#종목과 관련된 테마
 $query_stock_thema=" SELECT thema_no FROM `tbl_daily_thema_stock` WHERE stock_code= '".$GR_Vals['stock_code']."' group by thema_no";																																																												
$result_stock_thema=  mysqli_query($connect, $query_stock_thema); 

$stock_thema_tags="&nbsp; <img src='../img/ico_thema.gif'> &nbsp; <font style='color:#298A08;font-size:14px;'>";
 foreach($result_stock_thema as $t_no=> $t_value) {



 $stock_thema_tags.="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10001&thema_no=".$t_value['thema_no']."&key_word=".$all_thema_name[$t_value['thema_no']]['thema_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$all_thema_name[$t_value['thema_no']]['thema_name']." ";

 }

 $stock_thema_tags.="</font>";


echo "<html><body>";


echo "

  $style_css
";


	echo "<table  width=100% border=0 style='font-size:19px;'>";


echo"<tr  bgcolor='#FBEFFB'> <td colspan=4>".$srch_history_tags."</td></tr>";


									         	$arr_qry['qry']="select stock_code,stock_price,stock_yrate,stock_high_price,stock_rate,rel_news_no,chk_New,uDate,thema_no from tbl_daily_thema_stock where stock_code='".$GR_Vals['stock_code']."'  order by uDate desc";
												$rel_stock_array=php_mysql_Query($arr_qry,$connect);

																                                                                                                     
																																						if(!empty($rel_stock_array['value']) ) {  # start of if $find_daily_thema_stock

																																							   echo"<tr><td colspan=4 style='background-color:yellow;'><a href='$cur_php?mode=stock_std_list&uDate=".$srch_today."' target='news_d4'>종목</a>  ::																																																									   
																																																									
																																																															<a href='https://new.infostock.co.kr/stockitem?code=".$stock_info['stock_code']."' target='news_d5'>
																																																																								".$stock_info['stock_name']."</a> ".$stock_thema_tags."</td></tr>" ;

																																																										echo "<tr>$dot_line</tr>";


																																																 foreach($rel_stock_array['value'] as $mn=> $mn_value) {

																																																											 $query_news=" SELECT * FROM `tbl_news_scrap` WHERE no= '".$mn_value['rel_news_no']."' ";																																																												
																																																											 $result_news=  mysqli_query($connect, $query_news); 
																																																											 $chk_rel_news = mysqli_fetch_array($result_news);

																																																											
																																																											 #중복된 뉴스가 있는지를 체크하면서.. 멀티배열로 바꿔줌
																																																											 if($chk_rel_news['rel_no']>0)  $base_no=$chk_rel_news['rel_no'];
																																																											 else  $base_no=$chk_rel_news['no'];

																																																											        $chk_news[$base_no][]=$chk_rel_news;										
																																																													$thema_stock_multi[$base_no][]=$mn_value;
																																																											 
																																																	 }  

																																																	 foreach( $thema_stock_multi as $tsm=> $tsm_value) { # start of foreach $thema_stock_multi 

																																																		 	echo "<tr><td width=".$tbl_width['sc_name_min']."  colspan=2 width=100%>";

																																																														   echo "  <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:14px;' width=100% border=0> ";


																																																		
																																																	  for($ts=0;$ts<count($tsm_value);$ts++) { 

																																																		                                             $fdts_value=$tsm_value[$ts];
																																																													 $rel_news=$chk_news[$tsm][$ts];

																																																													# print_r($fdts_value);

																																																													 #print_r($rel_news);

																																																													  $rel_stock_tags="";
																																																													   $rel_thema_tags="";
																																																													   $get_scrap_grp="";
																																																																									$thema_tags="";	
																																																																									$stock_cmt_tags="";
																																																																									$vip_img="";
																																																																									

																																																																									$stock_cmt_wr_img="<img src='../img/pen.gif' onclick=\"open_popUp_scwr('".$fdts_value['stock_code']."','".$fdts_value['no']."','".$fdts_value['uDate']."')\">";

																																																																									$rw=3;

																																																																						
																																																																						  $up_day2=calender_str(3,13,$fdts_value['uDate']);	

																																																																									## 테마타이틀에 링크가 걸려 있으면 테크 할것
																																																																									
																																																																									$uDate_tags="<a href='$cur_php?mode=dnsl&uDate=".$fdts_value['uDate']."' target='news_d2'>".$up_day2['unix_str']."</font></a>";


																																																																									if($ts) 
																																																																												{
																																																																																	$rel_news_tit="";		
																																																																																	 $stock_info="";
																																																																																	 $rw=2;

																																																																												}

																																																																									else { 
																																																																											  $rel_news_tit="<tr>
																																																																	                               	<td colspan=2>$uDate_tags <a href='".$rel_news['news_link']."' target='news_d5' style='font-size:14px;color:blue;'>".$rel_news['news_title']."</a></td></tR>";				 
																																																																											  

																																																																									}

																																																																									# 그날의 종목 코멘트 가져오기
																																																																									
																																																																									
																																																																									if(!empty($stock_cmt['stock_cmt'])) {	
																																																																										$stock_cmt_tags="<br><br><table  style='border: 1px dashed orange; border-radius: 7px; background-color:white; border-spacing:3px;font-size:12px;color:#2E64FE;height:30px;padding:3px;' width=160px;><tr><td>".nl2br($stock_cmt['stock_cmt'])."</td></tr>";

																																																																										if(!empty($stock_cmt['rel_url']))$stock_cmt_tags.="<tr><td><img src='../img/ic/16-file-page.png' valign=bottom> <a href='".$stock_cmt['rel_url']."' target='news_d5' style='color:red;'>".$stock_cmt['rel_title']."</tD></tr>";

																																																																										$stock_cmt_tags.="</table>";
																																																																										$stock_cmt_wr_img="";
																																																																									}



																																																																									# 뉴스에서 관련 종목이 있다면.. 표시할 것

																																																																								if($rel_news['stock_code']) {
																																																																									$Rank_Vals=array('rel_news_no'=>$rel_news['no'],'dot_line'=>$dot_line);
																																																																									$rel_stock_tags=get_rel_stock_Rank($Rank_Vals,$connect);
																																																																									}

																																																																									# 뉴스에서 관련 테마가 있다면 표시할것	
																																																																																	  
																																																																										  ###시작 : 뉴스번호를 가지고 이전 테마정보 가져오기

																																																																										  if($rel_news['thema_no']>0) {
																																																																											
																																																																											 $scrap_Vals=array('no'=>$rel_news['no'],'max_width'=>"490px",'disp'=>"scrap_news");
																																																																	                                         $get_scrap_grp=get_scrap_grp($scrap_Vals,$connect);
																																																																											 
																																																																											 $thema_tags="&nbsp; <img src='../img/ico_thema.gif'> &nbsp;  <a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10001&thema_no=".$rel_news['thema_no']."&key_word=".$all_thema_name[$rel_news['thema_no']]['thema_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>"	.$all_thema_name[$rel_news['thema_no']]['thema_name']."</a>&nbsp;" ;

																																																																										  }
																																																													

																																																															echo $rel_news_tit;

																																																															echo "<tr><td>".$rel_stock_tags."</td></tr>";			 
																																																														
																																																															echo "<tr style='font-size:11px;line-height:20px;'><td style='color:#298A08;' >".$thema_tags."</td></tr>";
																																																															echo "<tr style='font-size:11px;line-height:20px;'><td style='color:#298A08;' >".$get_scrap_grp."</td></tr>";																																																				
																																																															

																																																														# }

																																																	     } # end of for

																																																		 echo "</table>";



																																																															echo "</td></tr>";
																																																															
																																																															if($rel_news['rel_no']==0)   echo "<tr>$dot_line</tr>";

																																																	  } # end  of foreach $thema_stock_multi 


																								}  # end of if $find_daily_thema_stock


																																															  echo "</table>";




												  
																					



echo "</body></html>";


exit;

#################################################################
} # end of top_price_daily_finish
#################################################################



############################################
function stock_cmt_writE($connect) {
###########################################
global $cur_php;
require "./env/e.fnc";
require "./env/inf.fnc";



$cur_year=date("Y");
$up_dir="./dta/stock/$cur_year"; # 첨부파일을 업로드할 디렉토리 .. 년도별로 관리

$test_on=0;

$GR_Vals=Get_Vals('mode');

$key_word=str_replace('\'','',$GR_Vals['key_word']);
$opt=$GR_Vals['opt'];


$stock_info=get_stock_info($GR_Vals['stock_code'],$connect); 



####   테마 신규 등록 또는 수정,  신규 테마스토리 입력


 


$qry="stock_code='".$GR_Vals['stock_code']."', stock_cmt='".addslashes($GR_Vals['stock_cmt'])."',vip='".$GR_Vals['vip']."', rel_news_no='".$GR_Vals['rel_news_no']."', rel_url='".$GR_Vals['rel_url']."', rel_title='".addslashes($GR_Vals['rel_title'])."', uDate='".$GR_Vals['uDate']."' ";



  if($opt=='insert') { ##  테마를 신규 등록

	  if($test_on) {

echo $qry;
exit;

}

	  if(empty($GR_Vals['stock_cmt'])) {

																			$alert_msg="내용을 입력해주세요";

																			echo "<body onload=\"alert('$alert_msg');window.history.back();\">";
																			exit;

																			}


																		                                                                        
																			  $query_ins="insert into tbl_daily_stock_cmt set  $qry ";
																			  $result_ins=mysqli_query($connect,$query_ins); 
																			  
																			  $new_ins=1;
							  }

  elseif($opt=='update') { ##  테마 이름 수정																		
																		   
                                                                         $query_update="update into tbl_daily_stock_cmt set $qry where no= '".$GR_Vals['no']."' ";
													                     $result_update=mysqli_query($connect,$query_update); 

																		 $new_ins=1;

												  }


 if($new_ins) {
	 												 echo "<body  onload='javascript:self.close();opener.location.reload();'>";

																exit;
							 }

###



				############  시작 :  검색어로 기존에  있던 테마이름 체크






				echo "<html><body>";


echo "
														   
					   <script type=\"text/javascript\">


   						        function      open_popUp(key_str) {

   																																		    var url ='$cur_php?mode=sc_wr&opt=insert&key_word='+key_str+'          ';

																																			var popupX = (window.screen.width / 2) ;
																																			var popupY= (window.screen.height / 2) - (1000 / 2);
																																			 
																																			 var size ='width=1000,height=1300,left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																			
																																			 var n=open(url,'stock_pop',size); 

																																			   n.focus(); 		
																									
																																	} // end of fnc ::: 


			



	                                         function      upload_file(up_dir,str) {
		                                       
												var Up_Dir = up_dir;
					                            var popup_X = event.screenX;	
												 var popup_Y = event.screenY;

												 var urls='$cur_php?mode=fuf&dir_st='+Up_Dir+'&str='+str+'';
											     var zz;

											     zz = window.open(urls, 'newWindow', 'width=500, height=500,left='+popup_X+',top='+popup_Y);

												  zz.focus();

											}


	                               function      upload_file_inner_html(tit,urls,str) {

									  // alert(tit);
									 //  return;


                                        var id_title='rel_title';
                                        var id_url='rel_url';

									   document.getElementById(id_title).value= tit;
   									   document.getElementById(id_url).value= urls;

}


			</script>

";

echo $style_css;

echo  "   						   <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;' > 

																										<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>
																										<input type='hidden'  name=mode  value='sc_wr'>			
																										<input type='hidden'  name=stock_code  value='".$GR_Vals['stock_code']."'>																							
																										<input type='hidden'  name=uDate  value='".$GR_Vals['uDate']."'>																							
																										<input type='hidden'  name=rel_news_no  value='".$GR_Vals['rel_news_no']."'>																							
																										<input type='hidden'  name=opt  value='insert'>									

																										<tr><td>&nbsp; <img src='../img/bul59.gif'> <font style='font-size:14px;'>".$GR_Vals['uDate']." &nbsp; &nbsp; <input type=submit value='등록' style='width:50px;cursor:hand;border-radius: 7px;border:dashed 1px orange;color:blue;'>
																										&nbsp;  &nbsp;  &nbsp;  *<input type='checkbox'  name='vip'  value='1'  >유망종목(VIP)
																										</td></tr>

																										
																										<tr>
																											<td class=form_nc style='font-size:16pt;font-weight:bold;border:dashed 1px gray;border-radius: 10px;color:black;width:400px; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px; text-align:center;background:yellow;'>
																											
																											".$stock_info['stock_name']."
																										    </td>

																										 </tR>

																										<tr><td align=right >																																														

																										
																										</td></tr>
																										
																										
																										<tr><td>
																											<textarea name='stock_cmt' style=\"width:485px; height:300px; overflow-x:hidden; overflow-y:auto;font-size:15px; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;border-radius: 10px;\" class=form_nc >".$GR_Vals['stock_cmt']."</textarea>
																										</td></tr>

																												<tr><td>
																										<input type='text' name='rel_title' id='rel_title' value='' $auto_clear_tag size='33'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>
																										<input type='text' name='rel_url' id='rel_url' value='' readonly size='20'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>

																										<input type=button value=\"첨부\"  class=form2 style='cursor:hand'  onclick=\"upload_file('".$up_dir."','stock')\">
																										</td></tr>
																											
																											</form></td></tr></table>

";




echo "</body></html>";

exit;
		



 ################### end of  Pax_Thema_writE() #######################
}
################### end of  Pax_Thema_writE()#######################





############################################
function get_stock_cmt($stock_code,$uDate,$opt,$connect) {
###########################################

  if($opt=='stock')        $qry="stock_code='".$stock_code."' ";
  elseif($opt=='day')   $qry="uDate='".$uDate."'  ";   
	   	   
      $query_cmt="SELECT vip,uDate,stock_code,stock_cmt,rel_news_no FROM `tbl_daily_stock_cmt` WHERE ".$qry."  limit 0,100";

	   $result_cmt=mysqli_query($connect,$query_cmt); 

						foreach($result_cmt as $c_no => $c_value){

  							   if($opt=='stock')          $return_cmt[$c_value['uDate']]=$c_value;                			           	   
								elseif($opt=='day')	  $return_cmt[$c_value['stock_code']]=$c_value;
		                              }


 return $return_cmt;


#################################################################
} # end of get_rel_stock_tags
#################################################################




#################################################################
function ref_lisT($connect) {
#################################################################
global $cur_php;
global $admin_info;
global $mobile;

require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');

if($admin_info['usr_level']==1) {
 $news_target="news_d4";		
 $disp_admin=1;

} else   $news_target="news"; 



if($GR_Vals['no']) {

           $query_up['qry']=" update `tbl_ref_memo`  set  ref_prg='".$GR_Vals['ref_prg']."' where no ='".$GR_Vals['no']."' ";
          $query_up['result']=1;
		  $result_up=php_mysql_Query($query_up,$connect); 

}




$ref_type_array=array(0,"유튜브","요리(레시피)","요리(맛집)");

for($tt=1;$tt<count($ref_type_array);$tt++) {

	 $ref_type_str.="<input type=radio name='ref_type' value='".$tt."' >".$ref_type_array[$tt]."";

}


																					$query_ref['qry']=" SELECT * FROM `tbl_ref_memo`  order by no desc ";
																					$result_ref=php_mysql_Query($query_ref,$connect); 

echo "<html><body width=100% border=0>";


echo ("
													
														   
														   <script type=\"text/javascript\">

		
                                                            function      submit_Confirm(v,chk_str) {		

																 chk_ref_type=0;
																
																
																																 //  폼으로 넘어온 변수 이름과 값을 확인

																																 if(0) {
																																				 for(loop = 0; loop < v.length; loop++)  alert(v[loop].name+ '==>' + v[loop].value);
																																				return;
																																			}

																													 var ref_type_chk = document.getElementsByName('ref_type');

																													for(var i=0;i<ref_type_chk.length;i++)	if(ref_type_chk[i].checked == true) chk_ref_type=1;

																									
																										         if(chk_ref_type==0) { alert('유형을 입력해주세요'); 
																												                                            return; 
																																						}

																																						

																											 chk_vals=document.getElementById(chk_str).value;

																										   if(chk_vals=='' || chk_vals=='타이틀을 적어주세요' ) { 
																											                                            alert('내용없음'); 
																										                                                return;
																																						}  // 제목이 없으면 등록 취소

																																																												

																										//  if (confirm(\"등록하시겠습니까?\")) {
																											  v.submit();
																												
																									//		} 
																																																																	
																							}




			</script>


  $style_css


											");



echo "<table><tr><td>";


if($disp_admin) {

                              			echo "<table border=0  width=100%><tr><td>";

																																					echo     "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px;'  width=100%> "; # start of 1번째  tbl


																																					echo  "<tr>
																																																																															

																																										<td> 
																																													<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>
																																													<input type=hidden name=mode  value='ref_update'>
																																																		<img src='../img/micon1.gif'> <a href='$cur_php?mode=ref_list'>스크랩</a> &nbsp; <input type='text' name=ref_title  id='ref_title' value='타이틀을 적어주세요' size='37'  class=form_nc $auto_clear_tag> urls <input type='text' name=ref_url  id='news_link' value='https://' size='14'  class=form_nc $auto_clear_tag>
																																																		<input type=button value=\"등록\"  class=form2 style='cursor:hand'  onclick=\"submit_Confirm(document.myform,'ref_title')\">																																																		   
																																																		   
																																									   </td>
																																								  </tr>";
																																					echo "<tr><td><img src='../img/micon1.gif'>코멘트
																																											 <input type='text'   id='ref_cmt'  name='ref_cmt' value='' size='75'  class=form_nc  style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;'>																																								
																																											 ";

echo "<Tr><td   style='border: 0px dashed orange; border-radius: 6px; background-color:#F8E0EC; border-spacing:3px;font-size:14px;' border=0 align=center>".$ref_type_str."</td></tr>";

																																					echo "</table></form>";
										echo "</td></tr><table>";
}

echo "</td></tr>";


echo "<tr><td>";
      

																												 echo  "<table  style='border: 1px dashed orange; border-radius: 7px; background-color:white; border-spacing:3px;height:30px;padding:3px;' width=95%> "; # start of 1번째  tbl

																																																		foreach($result_ref['value'] as $ref_key => $ref_value){

																																																						#     $base_day_str= calender_str(3,13,$k_date);

																																																				#		print_r($ref_value);

																																																				if($ref_value['ref_prg']) $chk_img="<a href='$cur_php?mode=ref_list&no=".$ref_value['no']."&ref_prg=0'><img src='../img/check_on.gif'></a>";
																																																				else $chk_img="<a href='$cur_php?mode=ref_list&no=".$ref_value['no']."&ref_prg=1'><img src='../img/check_off.gif'></a>";																																																		
																																																				  
																																																				  
																																																				  echo "<tr style='font-size:14px;'>
																																																				  
																																																				  <td style='text-align:center;' width=80px; rowspan=2>$chk_img ".$ref_type_array[$ref_value['ref_type']]."</td>
																																																				  <td width=600px;><a href='".$ref_value['ref_url']."' target='_blank'>".$ref_value['ref_title']."</a></td>																																																				  
																																																				  
																																																				  </tr>";			
																																																				  
																																																				  echo "<tr style='font-size:12px;color:gray;'><td><img src='../img/bul59.gif' > ".$ref_value['ref_cmt']."</td></tr>";

																																																				  

																																																		}

																												   echo  "</table> "; # start of 1번째  tbl
																					


echo "</td></tr></table>";



echo "</body></html>";

exit;
#echo"<BODY onLoad='javascript:self.close();' >";
#echo " window.onload = closeWindow(); ";

#################################################################
} # end of ref_lisT($connect)
#################################################################







#################################################################
function ref_updatE($connect) {  # 
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');


#print_r($GR_Vals);


$ref_title=addslashes ($GR_Vals['ref_title']);

$query_ins="insert into tbl_ref_memo set ref_title='$ref_title',ref_url='".$GR_Vals['ref_url']."' ,ref_cmt='".$GR_Vals['ref_cmt']."' ,ref_type='".$GR_Vals['ref_type']."'  " ;
$result_ins=mysqli_query($connect,$query_ins); 


if($result_ins) {
			 Header("Location:$cur_php?mode=ref_list");
		     
	        }


#################################################################
} # end of ref_updat($connect)
#################################################################


#################################################################
function thema_all($connect,$pdo) {  # d5★★★★★★★★ 전체 테마와 해당 종목 보여주기 i5 프레임
#################################################################
global $cur_php;
global $admin_info;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;
$today = date("Y-m-d");

$GR_Vals=Get_Vals('mode');


if($test_on) print_r($GR_Vals);

$opt_deco['type']=21;
$opt_deco['font']="14px";
$opt_deco['str']="%";

if($GR_Vals['mode_two']=='del') {

$del_qry="delete from tbl_daily_thema_stock where stock_code='".$GR_Vals['stock_code']."' " ;
													 if(!$test_on) $result_del=mysqli_query($connect, $del_qry); 
}

## 당일_10% 이상 종목만
if($GR_Vals['today_high']=='on') 	   { setcookie('opt[today_high]',1,time()+12800,'/');  $admin_info['today_high']=1; }
elseif($GR_Vals['today_high']=='off' )  { setcookie('opt[today_high]',1,time()-3600,'/'); $admin_info['today_high']=0; }

if($admin_info['today_high']) $today_high_tag="<img src='../img/check_on.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=thema_all&today_high=off'\" style='cursor:hand;'> 10%이상(+2개종목이상) 테마보기 ";
else  $today_high_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=thema_all&today_high=on'\" style='cursor:hand;'> 10%이상(+2개종목이상) 테마보기 ";

# 특정일과 비교하기
if($GR_Vals['history_uDate_c']=='on') 	   { setcookie('opt[history_uDate]',$GR_Vals['history_uDate'],time()+12800,'/');  $admin_info['history_uDate']=$GR_Vals['history_uDate']; }
elseif($GR_Vals['history_uDate_c']=='off')  { setcookie('opt[history_uDate]',$GR_Vals['history_uDate'],time()-3600,'/');  $admin_info['history_uDate']=0; }

if($admin_info['history_uDate']) $GR_Vals['history_uDate']=$admin_info['history_uDate'];

#print_r($GR_Vals);



#print_r($admin_info);

if($admin_info['usr_level']==1) {
			 $disp_admin=1;
}

$chk_high_price_rate=7;

$key_tag=str_replace("'",'',$GR_Vals['key_word']);

if($GR_Vals['thema_no'] and !empty($GR_Vals['thema_name'])) {
	                        
                                                                        $query_update="update tbl_thema_name set thema_name=".trim($GR_Vals['thema_name']).",finup_no=".trim($GR_Vals['finup_no'])." where thema_no= ".$GR_Vals['thema_no']." ";
													                     $result_update=mysqli_query($connect,$query_update); 

}

                     # 시작 :전체 테마종목 가져오기																									
						$sql = "SELECT CAST(thema_no AS char) AS thema_no, thema_name, finup_no, uDate  FROM tbl_thema_name";
						$raw_data = $pdo->query($sql)->fetchAll();
						$all_thema_name = array_column($raw_data, null, 'thema_no');

                     # 끝 :전체 테마종목 가져오기



                     # 시작 :전체 종목 명 가져오기
																										 // 1. PDO를 사용해 평범하게 전체 데이터를 가져옵니다. (초고속)
																										$sql = "SELECT stock_code, stock_name, stock_rate, stock_rate_rt, stock_high_price, stock_yrate, stock_price, stock_cap, uDate FROM all_stock_info";
																										$raw_data = $pdo->query($sql)->fetchAll();

																											// 2. 🚀 마법의 내장 함수 'array_column'을 사용하여 단번에 Key를 할당합니다!
																										$all_stock_name = array_column($raw_data, null, 'stock_code');
																										#	var_dump($all_stock_name);
                     # 끝 :전체 종목명 가져오기


                     # 시작 : 기준일 종목  가져오기
																	 if($GR_Vals['history_uDate']) {

																		 							       $arr_stock_history['qry']="select stock_code,stock_cap from all_stock_history_info where uDate='".$GR_Vals['history_uDate']."' ";										
																										   $arr_stock_history['keys'] ='stock_code';
																											$stock_history_array=php_mysql_Query($arr_stock_history,$connect);
                                                                                                          # 종목 네임 배열
																											$all_stock_history_name=$stock_history_array['multi_keys'];
																									
																										
																	 }

																						$query_stock['qry']="SELECT thema_no,stock_code,vip FROM `tbl_daily_thema_stock`  where thema_no>0 order by thema_no desc ";
																						$query_stock['keys']='thema_no';
																						$query_stock['multi_keys']=1;
																						$result_stock=php_mysql_query($query_stock,$connect); 

#     																					$stock_array=mysqli_fetch_array($result_news);







foreach( $result_stock['multi_keys'] as $stock_key => $stock_value) {

	                  $thema_name[]=$all_thema_name[$stock_key]['thema_name'];
					  $sort_get_value[]= $stock_value;

					}



array_multisort($thema_name, SORT_ASC, $result_stock['multi_keys']);

			echo "	<html>
						<body>
				  
					$style_css";

echo "
														   
					   <script type=\"text/javascript\">


	                   function      chg_thema_name(thema_no,thema_name) {

						                                                                                                                get_thema_name= prompt('테마이름을 변경하시겠습니까?',thema_name);

																																		get_finup_no= prompt('핀업 테마 번호를 변경하시겠습니까?',0);

																																		if( (get_thema_name==thema_name || get_thema_name==null) && get_finup_no==0 ){ 																																																															
																																					alert('동일합니다');																																		
																																					return;																																		
																																		}
																																
																																	 	 go_to_url_tags  ='$cur_php?mode=thema_all&thema_no=\''+thema_no+'\'&finup_no=\''+get_finup_no+'\'&thema_name=\''+get_thema_name+'\'';																								

																																		 //alert( go_to_url_tags);

                                                                                                                                           window.document.location.href=go_to_url_tags;
																									
																																	} // end of fnc ::: 





   						        function      open_popUp(no,opt) {

   																																		   if(opt==1)  var url ='$cur_php?mode=thema_merge&no='+no;

																																			var popupX = (window.screen.width / 2) ;
																																			var popupY= (window.screen.height / 2) - (1000 / 2);
																																			 
																																			 var size ='width=2000,height=1300,left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
																																			
																																			 var n=open(url,'thema_pop',size); 

																																			   n.focus(); 		
																									
																																	} // end of fnc ::: 




			</script>

";


echo  "<table style='border: 1px dashed orange; border-radius: 10px; border-spacing:7px; ' width='".$tbl_width['i3t']."'  border=0> "; # start of 1번째  tbl
echo "<tr><td colspan=3 style='font-size:13px;text-align:left;'> ".$today_high_tag."</td></tr>";
echo "<tr><td colspan=3 style='font-size:13px;text-align:right;'> $high_price_tag <span style='display:inline-block; width:30px;'></span></td></tr>";

####  과거에 등록한 데이터와 현재 가격을 비교, 기간 등락률

#
					$qry_stock_history="SELECT uDate FROM `all_stock_history_info` group by uDate desc  limit 0,9 ";										
					$result_stock_history=mysqli_query($connect,$qry_stock_history); 

				   $tag_history="<table><Tr><td><변동율비교></td>";

		foreach( $result_stock_history as $stock_history_key => $stock_history_value) {

				#
					if($stock_history_value['uDate']==$GR_Vals['history_uDate']) $hu_tag="<img src='../img/go_on2.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=thema_all&history_uDate_c=off'\" style='cursor:hand;'><font color=red style='font-weight:bold;'> ";
					else $hu_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=thema_all&history_uDate_c=on&history_uDate=".$stock_history_value['uDate']."#".str_replace("'",'',$GR_Vals['no'])."' \" style='cursor:hand;'> ";

				   $tag_history.= "<td style='font-size:14px;text-align:right;'>".$hu_tag."[".$stock_history_value['uDate']."]</td><td width=10px;></td>";

				}
				   $tag_history.="</tr></table>";

				echo "<tr><td colspan=3 style='font-size:13px;text-align:right;'>  $tag_history </td></tr>";

foreach( $result_stock['multi_keys'] as $stock_key => $stock_value) {

	  if($stock_key==0) $all_thema_name[$stock_key]['thema_name']="개별";

	    if($disp_admin) $pop_thema_tags="<a  onclick=\"chg_thema_name('".$stock_key."','".$all_thema_name[$stock_key]['thema_name']."')\" style='cursor:hand;'>  ";

        $bg_color="#610B38;";
		$box_color="#EFF2FB;";
		$high_price_ico_tag="";

     if($GR_Vals['no']==$stock_key) {
		
		  $bg_color="red;";
		  $box_color="#FBEFEF;";

 if($GR_Vals['high_price']) $high_price_ico_tag=" <a href=\"$cur_php?mode=thema_all&no=".$all_thema_name[$stock_key]['thema_no']."&key_word=".$GR_Vals['key_word']."#".$stock_key."\" style='cursor:hand;color:white;font-size:12px;'><img src='../img/check_on.gif'> ".$chk_high_price_rate."%이상";
 else 	 $high_price_ico_tag=" <a href=\"$cur_php?mode=thema_all&no=".$all_thema_name[$stock_key]['thema_no']."&key_word=".$GR_Vals['key_word']."#".$stock_key."\" style='text-decoration : none;'><img src='../img/check_off.gif'></a>  
 <img src='../img/ic/12-em-cross.png' style='cursor:hand;' onclick=\"open_popUp('".$all_thema_name[$stock_key]['thema_no']."',1)\"> ";

	 } 

                         $disp_on=0;
			              $s2n=0;
                          $mn=3;
						  $rt_rate=array();
          
						   $stock_t_value=array_unique($stock_value,SORT_REGULAR);

					foreach($stock_t_value as $st_key => $st_value) {

											  $base_stock_rate=$all_stock_name[$st_value['stock_code']]['stock_rate'];

											  $stock_high_rate=  round(($all_stock_name[$st_value['stock_code']]['stock_high_price']/($all_stock_name[$st_value['stock_code']]['stock_price']-$all_stock_name[$st_value['stock_code']]['stock_yrate'])-1)*100,2);
											
											  if($stock_high_rate>=10) $disp_on++;
										     $stock_t_value[$st_key]['rt_rate']=$base_stock_rate;
											  $rt_rate[$st_key]=$stock_t_value[$st_key]['rt_rate'];		
					}	

if($admin_info['today_high'] and $disp_on<2 )  continue; 


 # 테마번호로 테마이미지 찾아보기

	  ###시작 : 테마번호를 가지고 이전 테마정보 가져오기

       $scrap_Vals=array('no'=>$all_thema_name[$stock_key]['thema_no'],'uDate'=>$today,'max_width'=>"620px;",'disp'=>"thema_all",'font-size'=>"14px;");
       $get_scrap_grp=get_scrap_grp($scrap_Vals,$connect);
																														

  if($all_thema_name[$stock_key]['finup_no']) $finup_no_tags="<bR><a onclick=\"window.open('https://finance.finup.co.kr/Theme/".$all_thema_name[$stock_key]['finup_no']."','news','width=1270, height=1900');\" style='cursor:hand;'>핀업</a>";
	else $finup_no_tags="";

          echo "<tr><td><span id='".$all_thema_name[$stock_key]['thema_no']."'></td></tr><tr>
								<td style='border: 1px dashed orange; border-radius: 10px; background-color:".$bg_color." border-spacing:7px;color:yellow;text-align:center; '>
										".$pop_thema_tags.$select_style.$all_thema_name[$stock_key]['thema_name'].$select_br.$high_price_ico_tag.$finup_no_tags."</td>
								<td><table style='border: 1px dashed orange; border-radius: 10px; background-color:".$box_color." border-spacing:7px;font-size:13px; ' width='98%' border=0>";
				
					 array_multisort($rt_rate, SORT_DESC, $stock_t_value);

					foreach( $stock_t_value as $s2_key => $s2_value) {


                         if($all_stock_name[$s2_value['stock_code']]['stock_price']) 	 $stock_high_rate=  round(($all_stock_name[$s2_value['stock_code']]['stock_high_price']/($all_stock_name[$s2_value['stock_code']]['stock_price']-$all_stock_name[$s2_value['stock_code']]['stock_yrate'])-1)*100,2);

						if($admin_info['today_high'] and $stock_high_rate<10 ) continue;


							 $mode_no=$s2n%$mn;							

							 if($mode_no==0) echo "<Tr height='28px;'>";


							$infostock_open="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_code=".$s2_value['stock_code']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>"; 

							if($all_stock_name[$s2_value['stock_code']]['stock_price']) $stock_high_rate=  round(($all_stock_name[$s2_value['stock_code']]['stock_high_price']/($all_stock_name[$s2_value['stock_code']]['stock_price']-$all_stock_name[$s2_value['stock_code']]['stock_yrate'])-1)*100,2);
							
							if($stock_high_rate>15) $stock_name_style="font-weight:bold;font-size:14px;background-color:yellow;";  else $stock_name_style="color:#A4A4A4;"; 

										
                         if(!$all_stock_name[$s2_value['stock_code']]) echo "<font style='font-size:30px;color:red;font-weight:bold;'><a href='$cur_php?mode=thema_all&mode_two=del&stock_code=".$s2_value['stock_code']."'>종목삭제".$s2_value['stock_code']."</a>";



		 if($GR_Vals['history_uDate']) {


			 $stock_high_rate=  round(($all_stock_name[$s2_value['stock_code']]['stock_cap']/$all_stock_history_name[$s2_value['stock_code']]['stock_cap']-1)*100,2);

		 }



										$thema_Rate_Vals=array('finish_rate'=>$s2_value['rt_rate'],'high_rate'=>$stock_high_rate,'chk_high_rate'=>10,'disp_opt'=>$opt_deco,'stock_name'=>$all_stock_name[$s2_value['stock_code']]['stock_name'],'stock_name_style'=>$stock_name_style,'no_tbl'=>1);							
										
										$get_thema_rate_tags=get_thema_rate_tags($thema_Rate_Vals);											
							   
							   if($s2_value['vip']) $vip_tags="<img src='../img/ic/16-heart-red-xs.png'>";  else $vip_tags="";
								   if($s2_value['rt_rate']!=0) 							echo "<Td nowrap>".$vip_tags.$infostock_open.$get_thema_rate_tags."</td>";

						 if($mode_no==($mn-1)) echo " </Tr>";

						 $s2n++;


					}
		  
		  echo "</tr></table></td></tr>";

		  echo "<tr><Td width=25%></td><td width=75%>".$get_scrap_grp."</td></tr>";
}


echo  "</table>"; # end of 1번째  tbl


echo "</body></html>";

exit;


#################################################################
} # end of thema_list($connect)
#################################################################



#################################################################
function stock_analysis_list($connect) {  ##★★★★★★★★ 전략종목:최고거래량, 상한가 종목 sal
#################################################################
global $cur_php;
global $admin_info;
global $mobile;

require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');
#print_r($GR_Vals);

$test_on=0;

$today = date("Y-m-d");

$sal_cookie=($_COOKIE['sal']);
if($GR_Vals['sal_del']=="on" ) {  setcookie('sal[del]',1,time()+12800,'/'); 	
                                                   $sal_cookie['del']=1;												 
   }  
 else if($GR_Vals['sal_del']=="off" )
	{ setcookie('sal[del]',0,time()+12800,'/'); 	
      $sal_cookie['del']=0;													 
	  }

$GR_Vals['sal_del']= $sal_cookie['del'];

if(!$GR_Vals['sal_del'])  $sal_del_img="<a href='".$cur_php."?mode=sal&sal_del=on'><img src='../img/check_off.gif' style='cursor:hand;'> "; 
else   $sal_del_img="<a href='".$cur_php."?mode=sal&sal_del=off'><img src='../img/check_on.gif' style='cursor:hand;'> "; 										



  $interval_days=5;


# 타입별 갯수체크
	$qry_stock_cnt="SELECT  stg_type,count(*) as cnt  from `tbl_stock_analysis`  where DATE_Add(uDate_5,INTERVAL ".$interval_days." day)>='".$today."' group by stg_type ";										
   	$result_stock_cnt=mysqli_query($connect,$qry_stock_cnt); 

   foreach($result_stock_cnt as $ct=>$ct_value)   {  # # start of srt_array
	   $stg_cnt[$ct_value['stg_type']]=$ct_value['cnt'];
   }

# 타입이 없으면 상한가 16 으로 고정
    if(!$GR_Vals['stg_type']) $GR_Vals['stg_type']=16;		
 	$where_qry="where stg_type='".$GR_Vals['stg_type']."' and DATE_Add(uDate_5,INTERVAL ".$interval_days." day)>='".$today."' ";

$vals=array('db_str'=>"stock_stg",'tag_name'=>"stg_type",'dft'=>$GR_Vals['stg_type'],'cnt'=>$stg_cnt);
$gs_tags=get_select_tags($vals,$connect);

# 등록일 기준 향후 피드백할 날짜

                        $today_ptime=calender_str(1,0,time());
						$add_day=array(1,2,5,10,30);
				        $get_uDate_time=Get_analysis_Date(time(),$add_day);


						foreach($add_day as $add_d => $add_value)	$add_day_tag.="<td width=80px; align=center>D+".$add_value."</td>";						

				        $uDate_1th=$get_uDate_time[0];
				        $uDate_2th=$get_uDate_time[1];
						$uDate_3th=$get_uDate_time[2];
						$uDate_4th=$get_uDate_time[3];
						$uDate_5th=$get_uDate_time[4];

						$uDate_Insert_Tags="
															 <img src='../img/imoticon/num3/1.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_1' value='$uDate_1th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_1','','0')\" style='cursor:hand'>
															 <img src='../img/imoticon/num3/2.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_2' value='$uDate_2th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_2','','0')\" style='cursor:hand'>
															 <img src='../img/imoticon/num3/3.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_3' value='$uDate_3th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_3','','0')\" style='cursor:hand'>
															 <img src='../img/imoticon/num3/4.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_4' value='$uDate_4th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_4','','0')\" style='cursor:hand'>
															 <img src='../img/imoticon/num3/5.gif' title='시작일'><input type='text' name='uDate[]'  id='rtime_5' value='$uDate_5th' size='14' readonly class=form_nc onclick=\"check_mouse('myform.rtime_5','','0')\" style='cursor:hand'>";

					$tr_text_input= "<table border=0>
														<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
														   <input type=\"hidden\" name=\"mode\" value=\"sal\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update\">												
														
														<tr height='30px;' style='vertical-align:top;'>
														
														<td>
														<input type=submit value='등 록(TR0150)' style='width:100px;height:30px;cursor:hand;'>
														
														<textarea name='sal_price' style=\"vertical-align:top;width:405px; height:30px;border:dashed 1px gray;border-radius: 7px;\"></textarea>
														</td>							
														
															<td align=center>".$uDate_Insert_Tags."</td>
														<td><a href='".$cur_php."?mode=sal&del_no=all' style='color:red;font-weight:bold;'><img src='../img/ic/12-em-cross.png'></td>

											</tr>

											<tr>
											<td colspan=2>".$gs_tags['tag']." &nbsp;  <a href='$cur_php?mode=dn_file&stg_type=".$GR_Vals['stg_type']."'>다운로드</td>														
											<td><a href='prj_yehior.php?mode=trl&pop=si' style='font-size:12px;'>매매복기</a></td>

											</tR>
											
																																	
											</form>
											
									</table>".$calender_js;


							


# 데이터 받아서 등록해줌
		if($GR_Vals['mode_two']=='update') {

      #cur_today 삭제 할것
	  					$qry_del_stock="update `tbl_stock_analysis`  set cur_today=0  ".$where_qry."  ";										
   	                     $result_del_stock=mysqli_query($connect,$qry_del_stock); 


      

							   if($test_on)  print_r($GR_Vals); 

							   						 foreach($GR_Vals['uDate'] as $u_key=>$u_array)   { 

														    $q_key=$u_key+1;

														  $qry_uDate.="uDate_".$q_key."='".explode(' ',$u_array)[0]."',";

													  }

						   $as_vals=explode("\n",preg_replace("/[#\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['sal_price'])); # 불필요한 특수문자들 제거후

							   if($test_on)  print_r($as_vals);

						  foreach($as_vals as $si_key=>$si_array)   { 

									   if($si_key<1) continue;

									 #      [0] => 종목코드     [1] => 종목명     [2] => 현재가    [3] => 등락률    [4] => 거래량    [5] => 고가    [6] => 저가    [7] => 전일대비    [8] =>     [9] => 거래대금(백만)
										$stock_price_array=explode("\t",$si_array);   # 당일 매매 내역을 종목별로 배열에 할당
										if($stock_price_array[0]==null) continue;  # 공백은 패스

										$vol_cap=$stock_price_array[9]/100;

										$stg_price=$stock_price_array[5];

										if($GR_Vals['stg_type']==16) $add_top=" top='1', "; 

                                        # 기존에 등록된 종목이 있는지 체크
											$qry_chk_stock="SELECT  *  from `tbl_stock_analysis`  ".$where_qry."  and stock_code='".$stock_price_array[0]."' ";										
   	                                        $result_chk_stock=mysqli_query($connect,$qry_chk_stock); 
											if($result_chk_stock)$get_chk_stock=mysqli_fetch_array($result_chk_stock);	
                                           if($get_chk_stock) { $qry_stock="update tbl_stock_analysis set up_times=up_times+1,cur_today=1 ".$where_qry."  and stock_code='".$get_chk_stock['stock_code']."'"; }

										   else 	$qry_stock="insert  into  tbl_stock_analysis set  stg_type='".$GR_Vals['stg_type']."', up_times=1,cur_today=1, ".$add_top." stock_code='".$stock_price_array[0]."', stock_price='".$stock_price_array[2]."' , stock_high_price='".$stock_price_array[5]."', stock_rate='".$stock_price_array[3]."', stock_vol='".$stock_price_array[4]."', stock_vol_cap='".$vol_cap."', stg_price='".$stg_price."', ".$qry_uDate." uDate='".$today."'";

							 if($test_on)  print_r($qry_stock); 	  
							 else $result=mysqli_query($connect, $qry_stock); 

						  }

							if(!$test_on) Header("Location:$cur_php?mode=sal&stg_type=".$GR_Vals['stg_type']." ");

						exit;

						}
# 데이터 받아서 등록해줌


if($GR_Vals['del_no']) {

if($GR_Vals['del_no']=='all')  $qry_del="delete from tbl_stock_analysis where stg_price=0 or  uDate_5<  '".$today."'  ";
else  $qry_del="delete from tbl_stock_analysis where no='".$GR_Vals['del_no']."'";

							 if($test_on)  print_r($qry_del); 	  
							 else $result=mysqli_query($connect, $qry_del); 

  $udate_qry="uDate desc,";

}

else if($GR_Vals['top_no']) {

$qry_top="update tbl_stock_analysis set top=1 where no='".$GR_Vals['top_no']."'";

							 if($test_on)  print_r($qry_top); 	  
							 else $result=mysqli_query($connect, $qry_top); 

  $udate_qry="uDate desc,";


}

 if($GR_Vals['ord']=='rate') {

    $udate_qry="stg_type,";
	$ord_stg_type_img="<img src='../img/micon2.gif'>";

	$ord_stg_type_tag="";
    $ord_uDate_type_tag="<a href='".$cur_php."?mode=sal&stg_type=".$GR_Vals['stg_type']."'>";

} 
else  {  

  $udate_qry="uDate desc,";

  
  $ord_uDate_img="<img src='../img/micon2.gif'>";
  
  $ord_uDate_tag="";

  $ord_stg_type_tag="<a href='".$cur_php."?mode=sal&ord=rate&stg_type=".$GR_Vals['stg_type']."'>";
	
	}

$opt_deco['type']=1;
$opt_deco['str']="%";
$opt_deco['font']="11px;";

echo "<html><body>";
echo $style_css;

	$qry_stock="SELECT  *  from `tbl_stock_analysis`  ".$where_qry." order by cur_today desc,".$udate_qry."(stock_price/stg_price) desc  ";										
   	$result_stock=mysqli_query($connect,$qry_stock); 

echo "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;".$mobile_font_array['title']." padding:0px;'  width=1200px; align=center>";

echo "<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:14px;color:blue;' align=center  height='49px;' ><td width=180px; colspan=15>".$tr_text_input."</td></tr>";


echo "<tr style='border: 1px dashed blue; border-radius: 5px; background-color:yellow; border-spacing:7px;font-size:14px;color:blue;' align=center  height='49px;' >
<td width=180px;>".$sal_del_img."종목명 (#".$tot_tr_cnt.")</td><td width=10>#</td><td width=90px;>".$ord_stg_type_img.$ord_stg_type_tag."등록후</td><td width=70px;>".$ord_uDate_img.$ord_uDate_type_tag."경과일</td><td width=80px;>등락률</td><td width=100px;>거래대금(억)</td><tD width=90px;>매매기록</td>".$add_day_tag."</td></tr>";
  
   foreach($result_stock as $st=>$st_value)   {  # # start of srt_array

	     $get_uDate=array();
		 $grp="";
		 $tdv_tags="";
		# $href_tags="";

	      $stock_info=get_stock_info($st_value['stock_code'],$connect);  

          # 등록일 이후 매매내역 있는지 아이콘으로 표시해줄것
		  # 클릭시 s3에 등록일 이후의 매매내역을 표시해줄것
		  # 날짜와 번호가지고 올것

		  $qry['qry']= "select no,sell_Date,contents from tbl_trade_review where stock_code='".$st_value['stock_code']."' and sell_Date>= '".$st_value['uDate']."' ";
		  $result_tdv=php_mysql_Query($qry,$connect);     
		
		  if($result_tdv['value']){
			     foreach($result_tdv['value'] as $tdv=>$tdv_value) {
					 if($tdv_value['sell_Date']==$today) $today_tags="background-color:black;color:white;font-size:14px;font-weight:bold;"; else $today_tags="";
					 if($tdv_value['contents']) { $tdv_href_mode="tdv"; $tdw_img="<img src='../img/rep.gif'> "; }
					 else { $tdv_href_mode="tdw"; $tdw_img="<img src='../img/pen.gif'> "; }

			      $tdv_tags.=$tdw_img."<a href='prj_yehior.php?mode=".$tdv_href_mode."&pop=si&no=".$tdv_value['no']."' target='s3' style='font-size:12px;".$today_tags."'>".$tdv_value['sell_Date']."</a><br>";
				 }
		  }


	     $vs_rate=$st_value['stock_price']/$st_value['stg_price']-1;

    $stock_high_price_array=array(0,"-","-","-","-","-");
 

		if($st_value['grp']) {
														$grp="<a href='".$cur_php."?mode=sal_view&stock_no=".$st_value['no']."' target='s2' style='background-color:yellow;padding-bottom:5px;'>";
														
															$qry_stock_grp="SELECT  base_Date  from `tbl_stock_analysis_grp`  where stock_no=".$st_value['no']." ";										
														   	$result_stock_grp=mysqli_query($connect,$qry_stock_grp); 
																		   foreach($result_stock_grp as $gt=>$gt_value)   {  
																				$get_uDate[]=$gt_value['base_Date'];
																		   }
										}


    $stock_high_price_name=array(0,"stock_high_price_1","stock_high_price_2","stock_high_price_3","stock_high_price_4","stock_high_price_5");
    $stock_uDate_name=array(0,"uDate_1","uDate_2","uDate_3","uDate_4","uDate_5");

		for($dta=1;$dta<count($stock_uDate_name);$dta++) {

			$chk_price=$st_value[$stock_high_price_name[$dta]];
			$base_Date=$st_value[$stock_uDate_name[$dta]];

			if($base_Date==$today) 	$stg_color[$dta]="style=background-color:orange;"; else $stg_color[$dta]="";

			$href_tags="<a href='".$cur_php."?mode=sal_write&stock_code=".$st_value['stock_code']."&stock_no=".$st_value['no']."&base_Date=".$base_Date."&uDate=".$st_value['uDate']."' target='s2'>";

			     if($get_uDate)    if(in_array($base_Date,$get_uDate)) $href_tags="<font style='background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;padding-bottom:7px;padding-top:7px;padding-left:7px;padding-right:5px;'>";   				   
            
			if($chk_price) $stock_high_price_array[$dta]= $href_tags.cur_deco_txt($opt_deco,($chk_price/$st_value['stg_price']-1)*100,10,5,-5);
              	else $stock_high_price_array[$dta]=calender_str(3,12,$base_Date)['unix_str'];
		}


	 # 경과일
		$today_dt = new DateTime($today);
		$uDate_dt = new DateTime($st_value['uDate']);
		$days_int= date_diff($today_dt,$uDate_dt)->days;

		$up_day=calender_str(3,13,$st_value['uDate']);	

					if($days_int>30 or $GR_Vals['sal_del']) $del_tags="<a href='".$cur_php."?mode=sal&del_no=".$st_value['no']."' style='color:red;font-weight:bold;'><img src='../img/ic/12-em-cross.png'>"; else $del_tags="";

					if($st_value['top']) $top_img="<img src='../img/top.png'> "; else $top_img="";

				if($st_value['cur_today']) $cur_style="style=background-color:red;color:white;font-size:12px;font-weight:bold;"; else $cur_style="style='font-size:12px;'";

#	  $top_link="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_name=".$stock_info['stock_name']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>";
   


echo "<tr align=right height=50px;>
			<td align=left>".$grp.$top_img.$stock_info['stock_name']."</a><br><font  style='font-size:13px;'> ".$up_day['unix_str']."</td> 
			<td ".$cur_style.">".$st_value['up_times'].$cur_img."</td>
			<td>".$top_tags.cur_deco_txt($opt_deco,$vs_rate*100,10,5,-5)."</td>
			<td align=center>( ".$days_int." )</td>
			<tD>".cur_deco_txt($opt_deco,$st_value['stock_rate'],20,5,-5)."</td>
			<tD>".deco_txt($st_value['stock_vol_cap'],133,500)."억</td>
			<td >".$tdv_tags."</td>

			<td ".$stg_color[1].">". $stock_high_price_array[1]."</td>
			<td ".$stg_color[2].">". $stock_high_price_array[2]."</td>
			<td ".$stg_color[3].">". $stock_high_price_array[3]."</td>
			<td ".$stg_color[4].">". $stock_high_price_array[4]."</td>
			<td ".$stg_color[5].">". $stock_high_price_array[5]."</td>
		</tr>
";

   }




#################################################################
} # end of stock_analysis_list
#################################################################




#################################################################
function stock_std_inserT($connect) {  # 
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$test_on=0;

$GR_Vals=Get_Vals('mode');

#if($test_on)print_r($GR_Vals);

if(!$GR_Vals['stock_name']) { $stock_info=get_stock_info($GR_Vals['stock_code'],$connect); 

$GR_Vals['stock_name']=$stock_info['stock_name'];

$chk_new_ins=1;

}

if($GR_Vals['mode_two']=='update') {

 if($GR_Vals['pti']=="당일체결상세(TR0110)") $GR_Vals['pti']="";
 if($GR_Vals['pti_today']=="거래내역(TR 0606)") $GR_Vals['pti_today']="";


      if($GR_Vals['pti']) {


													$chk_find_utime_qry="select uTime from tbl_stock_trade_history_signal where stock_code='".$GR_Vals['stock_code']."' and uDate='".$GR_Vals['uDate']."' and find_uTime=1 "  ;
													$result_chk_find_utime=mysqli_query($connect, $chk_find_utime_qry); 
													$chk_find_uTime=mysqli_fetch_array($result_chk_find_utime);				


													 $del_qry="delete from tbl_stock_trade_detail where stock_code='".$GR_Vals['stock_code']."' and uDate='".$GR_Vals['uDate']."'" ;
													 if(!$test_on) $result_del=mysqli_query($connect, $del_qry); 

													   $as_vals=explode("\n",preg_replace("/[#\&\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['pti'])); # 불필요한 특수문자들 제거후
												   
														   foreach($as_vals as $si_key=>$si_array)   {  # # start of si_array

																				   $stock_price_array=explode("\t",$si_array);   # 당일 매매 내역을 종목별로 배열에 할당
																				   if($test_on)  print_r($stock_price_array);

																				   if($si_key<2) continue;
																				   if(empty($stock_price_array[0])) continue;
																				   if(empty($stock_price_array[8])) continue; # 매수체결이 0 이면 미등록


																			#             [0] => 시간    [1] => 매도호가    [2] => 매수호가    [3] => 체결가    [4] => 전일대비    [5] =>     [6] => 등락률    [7] => 매도체결    [8] => 매수체결    [9] => 순매수량    [10] => 누적거래량    [11] => 대금(백만)    [12] => 체결강도
																			# 미니 :  [0] => 시간 [1] => 매도량 [2] => 매수량 [3] => 순매수량 [4] => 누적거래량 [5] => 대금  

																					  $uTime= substr($stock_price_array[0],0,4);  # 시간을 시:분으로 나눔

																					  if($GR_Vals['find_uTime']==1 and $si_key==2 and $uTime<1530) { 

																						  $qry_stock_signal="insert into  `tbl_stock_trade_history_signal`   set  uTime='".$uTime."' ,uDate='".$GR_Vals['uDate']."' , stock_code='".$GR_Vals['stock_code']."',  find_uTime=1    ";
																						  mysqli_query($connect, $qry_stock_signal); 
																						  $uTime_Qry=",find_uTime=1";																																												  
																						  }
																						  
																						  else {																						  
																							       $uTime_Qry="";
																								  if($chk_find_uTime['uTime']==$uTime) $uTime_Qry=",find_uTime=1";																																												  
																						  }
																						

																					  $stock_vol_cap= $stock_price_array[11]/100;  # 거래대금 백만 /100 => 억, 소수점두자리

																				  $qry_stock="insert into tbl_stock_trade_detail set  stock_code='".$GR_Vals['stock_code']."',uDate='".$GR_Vals['uDate']."', uTime='".$uTime."', tr_price='".$stock_price_array[3]."',tr_rate_vs='".$stock_price_array[6]."', sell_qty='".$stock_price_array[7]."', buy_qty='".$stock_price_array[8]."',   net_qty='".$stock_price_array[9]."',  vol_cap='".$stock_vol_cap."'  ".$uTime_Qry." ";
																				 
																				 if($test_on) { print_r($qry_stock); }
																					 else $result=mysqli_query($connect, $qry_stock); 
																		
														   } # end of si_array


													   # 시작: 데이타 업데이트후에 등락률,거래대금 계산해서 업데이트 해줄것
														  $sort_qry="select * from tbl_stock_trade_detail where stock_code='".$GR_Vals['stock_code']."' and uDate='".$GR_Vals['uDate']."' order by uTime"  ;
														  $result_sort=mysqli_query($connect, $sort_qry); 
														 
																   foreach($result_sort as $srt_key=>$srt_array)   {  # # start of srt_array

																	   if($srt_key==0) $net_qty_acc=$srt_array['net_qty'];
																	   
																	   if($srt_key>0) {
																			  
																			  $tr_rate= ($srt_array['tr_price']/$prv_tr_price-1)*100;
																			  $tr_cap=  $srt_array['vol_cap']-$prv_vol_cap;
																			  $net_qty_acc=$prv_net_qty_acc+$srt_array['net_qty'];

																				$qry_rate="update tbl_stock_trade_detail set   net_qty_acc='".$net_qty_acc."',tr_rate='".$tr_rate."',tr_cap='".$tr_cap."'  where no='".$srt_array['no']."'";
																					 
																					 if($test_on) { print_r($qry_rate); }
																						 else $result=mysqli_query($connect, $qry_rate); 
																	   }
																			  $prv_tr_price=$srt_array['tr_price'];
																			  $prv_vol_cap=$srt_array['vol_cap'];
                                                                     		  $prv_net_qty_acc=$net_qty_acc;
																   }  # end of srt_array
													   # 끝: 데이타 업데이트후에 등락률,거래대금 계산해서 업데이트 해줄것

													   													

									  }



      if($GR_Vals['pti_today']) {  # start of pti_today 당일 매매내역을 업데이트 시켜주기

        	  $del_history_qry="delete from tbl_stock_trade_history where stock_code='".$GR_Vals['stock_code']."' and uDate='".$GR_Vals['uDate']."'" ;
              if(!$test_on) $result_history_del=mysqli_query($connect, $del_history_qry); 


	   $as_vals_today=explode("\n",preg_replace("/[#\&\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['pti_today'])); # 불필요한 특수문자들 제거후

      # TR6060    
      # [0] => 종목코드 [1] => 종목명 [2] => 금일매수 [3] => [4] => [5] => 금일매도 [6] => [7] => [8] => 수수료 제세금 [9] => 손익금액 [10] => 수익률 [11] => 대출일 [12] => 신용구분 [13] => 이전 매입가

	   	   foreach($as_vals_today as $sit_key=>$sit_array)   {  # # start of si_array

								   $stock_today_array=explode("\t",$sit_array);   # 당일 매매 내역을 종목별로 배열에 할당

						   		   if($test_on)  print_r($stock_today_array);

								   if($sit_key==1) $base_day=$stock_today_array[0];

                       			   if($sit_key<1) continue;
                                   if(empty($stock_today_array[0])) continue;

								   echo $base_day;

									if($base_day<>$stock_today_array[0]) continue;


							#         [0] => 거래일    [1] => 체결시간    [2] => 매수평균가    [3] => 수량    [4] =>     [5] => 매도평균가    [6] => 수량

                					  $uTime= substr($stock_today_array[1],0,4);  # 시간을 시:분으로 나눔

									  if($stock_today_array[2]>0)   $tr_qry="tr_buy_price='".$stock_today_array[2]."', tr_buy_qty='".$stock_today_array[3]."'";
									  else $tr_qry="tr_sell_price='".$stock_today_array[5]."' ,   tr_sell_qty='".$stock_today_array[6]."'";

								 # $qry_stock="update tbl_stock_trade_detail set    ".$tr_qry."  where stock_code='".$GR_Vals['stock_code']."' and uTime='".$uTime."'";
								  $qry_stock="insert into tbl_stock_trade_history set  stock_code='".$GR_Vals['stock_code']."',uDate='".$GR_Vals['uDate']."', uTime='".$uTime."',".$tr_qry." ";
				    	  	     
								 if($test_on) { print_r($qry_stock); }

									 else $result=mysqli_query($connect, $qry_stock); 
						
		   } # end of si_array
             

	  } # end of pti_today

              # 시작 :거래내역을 체결상세에 업데이트 해줄것
						  $up_history_qry="select * from tbl_stock_trade_history where stock_code='".$GR_Vals['stock_code']."' and uDate='".$GR_Vals['uDate']."' order by uTime"  ;
						  $result_history=mysqli_query($connect, $up_history_qry); 
						 
						 if($result_history) {
															   foreach($result_history as $his_key=>$his_array)   {  # # start of srt_array

																   	  if($his_array['tr_buy_price']>0)   $tr_qry="tr_buy_price='".$his_array['tr_buy_price']."', tr_buy_qty='".$his_array['tr_buy_qty']."'";
								                                    	  else $tr_qry="tr_sell_price='".$his_array['tr_sell_price']."', tr_sell_qty='".$his_array['tr_sell_qty']."'";


																   $qry_tr_up="update tbl_stock_trade_detail set   ".$tr_qry."  where    stock_code='".$his_array['stock_code']."' and uDate='".$his_array['uDate']."' and uTime='".$his_array['uTime']."'      ";
																								 if($test_on) { print_r($qry_tr_up); }
																	 else $result_tr_up=mysqli_query($connect, $qry_tr_up); 

															   }
						 }
                     #끝 :거래내역을 체결상세에 업데이트 해줄것

							if(0) {
								  if($GR_Vals['pti']) {
																		# 대금 1억 미만은 지울것
																		  $del_tr_qry="delete from tbl_stock_trade_detail where stock_code='".$GR_Vals['stock_code']."' and uDate='".$GR_Vals['uDate']."' and tr_cap<1  and tr_buy_price=0 and  tr_sell_price=0 and find_uTime=0" ;
																		
																			 if(!$test_on) { $result_del=mysqli_query($connect, $del_tr_qry); 
																									  table_auto_increment('tbl_stock_trade_detail',$connect);  # 삭제후 번호를 마지막 번호로 셋팅

																									 # echo $del_tr_qry;
																									# exit;
																			 } else exit;

															  }
							}


}

else
	{
			$tr_text_input= "<table border=0>

  <tr align=center style=' background-color:yellow;font-size:30px; font-weight:bold;' height=30px;><td>".$GR_Vals['stock_name']."</td></tr>

			                               <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
										   <input type=\"hidden\" name=\"mode\" value=\"stock_std_ins\">
										  <input type=\"hidden\" name=\"mode_two\" value=\"update\">
										  <input type=\"hidden\" name=\"stock_code\" value=\"".$GR_Vals['stock_code']."\">
										  <input type=\"hidden\" name=\"uDate\" value=\"".$GR_Vals['uDate']."\">

												 
												 <tr align=\"left\">
											  <td align='left' style='padding-top:15px;' colspan=2>     									
											   ".$GR_Vals['uDate']."
										
												<input type=submit value='등 록' class=form_nc style='width:60px;cursor:hand;'>                    							
																								
													 </td>
													 </tr>
																										 
													 ";


  if(!$chk_new_ins) $tr_text_input.="<tr><td>*당일체결 상세(TR 0606)</td></tr><tr><td><textarea name=pti_today style=\"width:755px; height:200px;\"></textarea></td></tr>";

				$tr_text_input.= "				
				<tr><td>*체결분석(TR 0110)</td></tr>
				<tr><td><textarea name=pti style=\"width:755px; height:1200px;\"></textarea></td></tr>				                                    ";

				$tr_text_input.="</table></form>";

echo $tr_text_input;

	}




if($result) {
	if(!$test_on) 			 Header("Location:$cur_php?mode=stock_std_list&stock_code=".$GR_Vals['stock_code']."&uDate=".$GR_Vals['uDate']."");
		     
	        }


#################################################################
} # end stock_pt_inserT
#################################################################


############################################
function stock_analysis_writE($connect) {   ##★★★ sal_write 전략종목 개별 그래프 등록: 
###########################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$test_on=0;

$GR_Vals=Get_Vals('mode');

if($test_on) print_r($GR_Vals);

    # 변수 할당

$today= date("Y-m-d");   


	$stock_info=get_stock_name_info($GR_Vals['stock_code'],$connect);

# 컨텐츠가 있다면 DB에 업데이트 할것

		if($GR_Vals['contents']) {

						 $skip_Array=array('');

						# 시작: 받은 자료를 가지고 쿼리로 만듬
						foreach ($GR_Vals as $pims_key => $pims_value) { # start of cust_val		 

						  if(in_array($pims_key,$skip_Array)) continue;
						#  if($pims_value=="") $pims_value=0;

						  $up_qry.="$pims_key='$pims_value',";
		}

				$up_qry.="uDate='$today'";
# 끝: 받은 자료를 가지고 쿼리로 만듬

#if($GR_Vals['src_no'])  $query="update esguide_hobby set  $up_qry where src_no=".$GR_Vals['src_no'];  
#else 
	$query="insert into tbl_stock_analysis_grp set $up_qry"; 


if($test_on) { echo $query; exit; }
else  $result=mysqli_query($connect,"$query");            


if($result) {

	 $qry_stock_grp="update  `tbl_stock_analysis`   set  grp=grp+1  where no='".$GR_Vals['stock_no']."'    ";
     $result_grp=mysqli_query($connect,$qry_stock_grp); 

    echo "<body  onload=\"javascript:window.open('".$cur_php."?mode=pop_url&pop_type=3&stock_no=".$GR_Vals['stock_no']."','pop_hidden','width=10, height=10')\">";

		 exit;
}



}
## 업데이트 끝


  echo  "

    <html> 

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

 <body>

	";


echo ("	  

		<table width=1200px  border=\"0\" cellspacing=\"0\" cellpadding=\"10\" align=\"CENTER\" valign=\"MIDDLE\" bgcolor=white>
		
		<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
		<input type=\"hidden\" name=\"mode\" value=\"sal_write\">
        <input type=\"hidden\" name=\"base_Date\" value=\"".$GR_Vals['base_Date']."\">
        <input type=\"hidden\" name=\"stock_no\" value=\"".$GR_Vals['stock_no']."\">

				 <tr align=\"left\">

					 <td align='left' style='padding-top:15px;' colspan=4>     
					    <font style='font-size:20px;font-weight:bold;'>".$stock_info['stock_name']."</font>

						 등록일 : <".$GR_Vals['uDate']."> 
					     기준일 : <".$GR_Vals['base_Date']."> 
  		  				 <input type=button value='등 록' onclick=\"javascript:chkfrm(document.myform);\" class=form_nc style='width:80px;cursor:hand;'>                    							
					 </td>					 
					</tr>
");

	echo ("

   <tr>
		<td colspan=4>						 
  ");

$default_cts="<font style='font-size:20px;'>&nbsp; ";

				# 시작 :스마트 에디터 불러오기
				echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
				echo"<textarea name=contents id=\"ir1\" style=\"width:1150px; height:1000px; display:none;\">$default_cts</textarea>";

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




############################################
function stock_analysis_vieW($connect) {   ##★★★ sal_write 전략종목 개별 그래프 등록: 
###########################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$test_on=0;

$GR_Vals=Get_Vals('mode');

if($test_on) print_r($GR_Vals);

    # 변수 할당

$today= date("Y-m-d");   

  echo  "

    <html> 
	 <body>

	";


							$qry_stock_sal="SELECT *  from `tbl_stock_analysis`  where no=".$GR_Vals['stock_no']." ";										
						   	$result_stock_sal=mysqli_query($connect,$qry_stock_sal); 

							$st_value=mysqli_fetch_array($result_stock_sal);				
							$stock_info=get_stock_info($st_value['stock_code'],$connect);  


		$today_dt = new DateTime($today);
		$uDate_dt = new DateTime($st_value['uDate']);
		$days_int= date_diff($today_dt,$uDate_dt)->days;

		$up_day=calender_str(3,13,$st_value['uDate']);	

	     $vs_rate=$st_value['stock_price']/$st_value['stg_price']-1;

   $stock_high_price_name=array(0,"stock_high_price_1","stock_high_price_2","stock_high_price_3","stock_high_price_4","stock_high_price_5");
   $stock_uDate_name=array(0,"uDate_1","uDate_2","uDate_3","uDate_4","uDate_5");
   $add_day=array(0,1,2,5,10,30);


echo "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;padding:5px;'  width=1200px; align=center>";

     echo "<tr style='background-color:yellow;'>
					
					<td style='font-size:40px;font-weight:bold;padding-left:10px;' width=400px;>".$stock_info['stock_name']."</td>
					<TD><font  style='font-size:20px;' width=100px;> ".$up_day['unix_str']." ( ".$days_int." )</td>					
					<td>(등록가격) ".deco_txt($st_value['stg_price'],3,0)."
					       <br>(현재가격) ".deco_txt($st_value['stock_price'],3,0)."</td>
					<td>".cur_deco_txt($opt_deco,$vs_rate*100,10,5,-5)."</td>				
					<tD>(거래대금) ".deco_txt($st_value['stock_vol_cap'],133,500)."억</td>

					
				</Tr>	";


				 #  	$result_stock_grp=mysqli_query($connect,$qry_stock_grp); 

					$arr_grp['qry']="SELECT *  from `tbl_stock_analysis_grp`  where stock_no=".$GR_Vals['stock_no']." ";  
				    $result_stock_grp=php_mysql_Query($arr_grp,$connect);


				
if($result_stock_grp['value'] ) 
		foreach($result_stock_grp['value'] as $gt=>$gt_value)   {  

			      $stock_grp[$gt_value['base_Date']]=$gt_value;
		}




 echo "";

  for($sd=1;$sd<count($stock_uDate_name);$sd++){

	  $stock_high_price="";

      $chk_price=$st_value[$stock_high_price_name[$sd]];

  	if($chk_price) $stock_high_price= cur_deco_txt($opt_deco,($chk_price/$st_value['stg_price']-1)*100,10,5,-5);
           

  $border_style="style='border-radius: 5px;padding-left:10px;padding-bottom:10px;padding-top:10px;'";

echo ("	  
   <Tr><td colspan=10 >
				<table width=100% style=' border: 1px dashed orange; border-radius: 10px;cell-padding:10px;border-spacing:1px;' >
			   <Tr style='background-color:yellow;' height=40px;><td width=200px; ".$border_style.">( D+".$add_day[$sd]." ) ".$st_value[$stock_uDate_name[$sd]]."</td>
			   <td  ".$border_style."> (등록후 최고 수익률) ".$stock_high_price."</td></tr>
				
			   <tr>
					<td colspan=4>						 
					".$stock_grp[$st_value[$stock_uDate_name[$sd]]]['contents']."
					   
					
					</td>
					</tr>
				</table>
		</td></tr>

  ");


  }

	


echo "
      </table>  <!-- start of table 000 -->
      ";


 ################### end of sub_analysis_view_writE #######################
}
################### end of sub_analysis_view_writE #######################





#################################################################
function stock_std_cmt($connect) {  ##★★★★★★★★ [0110] 체결상세 데이터 업데이트
#################################################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;

$today = date("Y-m-d");
$GR_Vals=Get_Vals('mode');

#$stock_info=get_stock_info($GR_Vals['stock_code'],$connect);

if($test_on)print_r($GR_Vals);

if($GR_Vals['mode_two']=='del') {

    $del_tbl_array=array("tbl_stock_trade_detail","tbl_stock_trade_history","tbl_stock_trade_history_cmt","tbl_stock_trade_history_signal");

		for($dta=0;$dta<count($del_tbl_array);$dta++) {
			$del_qry="delete from ".$del_tbl_array[$dta]." where uDate='".$GR_Vals['uDate']."' and stock_code='".$GR_Vals['stock_code']."' " ;
			mysqli_query($connect,$del_qry); 

			 table_auto_increment($del_tbl_array[$dta],$connect);  # 삭제후 번호를 마지막 번호로 셋팅
		}

	if(!$test_on) 			 Header("Location:$cur_php?mode=stock_std_list&uDate=".$GR_Vals['uDate']."");
	exit;
}

if($GR_Vals['no']) {
			
			$qry_stock_std="SELECT  * from `tbl_stock_trade_detail`  where no=".$GR_Vals['no']." ";										
			$result_stock_std=mysqli_query($connect,$qry_stock_std); 
			$stock_std=mysqli_fetch_array($result_stock_std);					
			
}

if($test_on) { 

	echo $qry_stock_std."<Br>";
	
	print_r($stock_std); 
	}


if($GR_Vals['mode_two']=='update_signal') {
 
 $skip_Array=array('no','mode_two');
 $qry_signal= make_qry($GR_Vals,$skip_Array,0);

                           $del_signal_qry="delete from tbl_stock_trade_history_signal where uTime='".$stock_std['uTime']."' and uDate='".$stock_std['uDate']."' and stock_code='".$stock_std['stock_code']."' " ;
							 if(!$test_on)  mysqli_query($connect, $del_signal_qry);  

						  $qry_stock_signal="insert into  `tbl_stock_trade_history_signal`   set  uTime='".$stock_std['uTime']."' ,uDate='".$stock_std['uDate']."' , stock_code='".$stock_std['stock_code']."',  ".$qry_signal."    ";

						if($test_on) print_r($qry_stock_signal); 
						else $result=mysqli_query($connect,$qry_stock_signal); 

}

elseif($GR_Vals['mode_two']=='update_updn') {

 $skip_Array=array('no','mode_two');
 $qry_updn= make_qry($GR_Vals,$skip_Array,0);

 $qry_stock_updn="insert into  `tbl_stock_trade_updn`   set  uTime='".$stock_std['uTime']."' ,uDate='".$stock_std['uDate']."' , stock_code='".$stock_std['stock_code']."',  ".$qry_updn."    ";

 						if($test_on) print_r($qry_stock_updn); 
						else $result=mysqli_query($connect,$qry_stock_updn); 

} # exit update_updn


if($result) {
	if(!$test_on) 			 Header("Location:$cur_php?mode=stock_std_list&stock_code=".$stock_std['stock_code']."&uDate=".$stock_std['uDate']."");
	
exit;
		             }




elseif($GR_Vals['mode_two']=='grp_ins') {

                 if($GR_Vals['uTime']) { $qry_grp="insert into  `tbl_stock_trade_history_cmt`   set uDate='".$GR_Vals['uDate']."' , stock_code='".$GR_Vals['stock_code']."'  , cmt='".$GR_Vals['cmt']."'   , uTime='".$GR_Vals['uTime']."' ,contents='".$GR_Vals['contents']."'   ";

                                             				 #$qry_detail_cmt="update  `tbl_stock_trade_history_cmt`   set chk_cmt=1 where uDate='".$GR_Vals['uDate']."' and stock_code='".$GR_Vals['stock_code']."'  and uTime='".$GR_Vals['uTime']."' ";
															 #mysqli_query($connect,$qry_detail_cmt);  # 코멘트 남기면 1분 체결분석에 데이타를 넣어야 함

													 }
						  else $qry_grp="insert into  `tbl_stock_trade_history_grp`   set uDate='".$GR_Vals['uDate']."' , stock_code='".$GR_Vals['stock_code']."'  , cmt='".$GR_Vals['cmt']."'   , contents='".$GR_Vals['contents']."'   ";

						  						if($test_on) print_r($qry_grp); 
						else  { $result=mysqli_query($connect,$qry_grp); 


											echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=stock_std_list&stock_code=".$GR_Vals['stock_code']."&uDate=".$GR_Vals['uDate']."\";
													 self.close();
												</script>	 
										";		 

								}

                          exit;


}
  
if($GR_Vals['mode_two']=='grp_view') {

	                                                     if($GR_Vals['no']) $arr_grp['qry']="SELECT * FROM `tbl_stock_trade_history_cmt`  where no='".$GR_Vals['no']."' ";  
														 else  $arr_grp['qry']="SELECT * FROM `tbl_stock_trade_history_grp`  where no='".$GR_Vals['grp_no']."' ";  

													     $get_grp=php_mysql_Query($arr_grp,$connect);

															$g_value=$get_grp['value'][0];
															 $contents= $g_value['contents'];

																echo "        <table width='100%' align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  ><tr><td> ";  ## start of table 000 

																  echo "<table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:15px;' width=99%>"; ## start of table 000 -001
																
																  echo "<Tr><td colspan=2 style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:60px;font-size:25px;border-spacing:3px;background-color:yellow; '> &nbsp; (".$g_value['uDate'].") ".nl2br($g_value['cmt'])."</td></tr>";
																   echo "<Tr><td colspan=2 onclick='javascript:self.close();' style='cursor:hand;'>".$g_value['contents']."</td></tr>";

echo "<Tr><td colspan=2 align=center>
<input type=button value='창 닫기' onclick=\"javascript:self.close();\" style='width:150px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'> </td></tr>";

echo "</table></td></tr></table>";	

}


elseif($GR_Vals['mode_two']=='write') {

  if($test_on) print_r($stock_std);

 if($stock_std['uTime'])
	{

	 $input_uTime="<input type='hidden' name=uTime value='".$stock_std['uTime']."'>";

	}

   echo $style_css;

										echo "
																  <script type=\"text/javascript\">
																													
																													function     submit_Confirm(v) {		
																																																  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
																																																  contents= document.getElementById('ir1').value;

																																																  if(v.cmt.value=='코멘트') v.cmt.value='';

																																																if(contents=='<p>&nbsp;</p>') { 
																																																				 alert('내용없음'); 
																																																				   return;
																																																	}  
																																																																																														
																																											  v.submit();

																																							} // end of submit_Confirm
																			</script>";

										echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:15px;' width=100% align=center border=0> "; # start of 1번째  tbl
										echo "<tr><td><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
																																																					<input type='hidden' name=mode value='stock_std_cmt'>
																																																					<input type='hidden' name=mode_two value='grp_ins'>	
																																																					 $input_uTime
																																																					<input type='hidden' name=uDate value='".$GR_Vals['uDate']."'>	
																																																					<input type='hidden' name=stock_code value='".$GR_Vals['stock_code']."'>	

												 </td></tr>";
								
										echo "<tr><td>
										<textarea name='cmt' style=\"width:1185px; height:70px;font-size:20pt;border-radius: 7px;font-weight:bold;border:dashed 1px gray; overflow-x:hidden; overflow-y:auto;font-size:14pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc $auto_clear_tag >코멘트</textarea></td></tr>";
									

										echo "<tr><td >";

														# 시작 :스마트 에디터 불러오기
														echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
														echo"<textarea name=contents id=\"ir1\" style=\"width:99%; height:1050px;display:none;\"><1분봉><p><br></textarea>";

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


	                                	echo "<tr><td align=center> <input type=button value='등 록' onclick=\"javascript:submit_Confirm(document.myform);\" style='width:150px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'>  <input type='hidden' name=today_tr value='1'></td> </tr>";

																				
										   echo  "</table> "; # start of 1번째  tbl

}




#################################################################
} # end of stock_std_cmt($connect)
#################################################################


#################################################################
function stock_cmt($connect) {  # 주식 투자 원칙 
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$test_on=0;

$GR_Vals=Get_Vals('mode');

if($test_on)print_r($GR_Vals);

if($GR_Vals['mode_two']=="insert") {

 $skip_Array=array('mode_two');
 $qry_signal= make_qry($GR_Vals,$skip_Array,0);

 				$query_max_no="select max(ord_no) as mn from tbl_stock_cmt ";
				$result_max_no=mysqli_query($connect,$query_max_no);
				$max_num = mysqli_fetch_array($result_max_no, MYSQLI_ASSOC);
				$max_no=$max_num['mn']+1;

 $qry_stock_cmt="insert into  `tbl_stock_cmt`   set  ord_no='".$max_no."', ".$qry_signal."    ";

if($test_on)print_r($qry_stock_cmt);
else  $result=mysqli_query($connect,$qry_stock_cmt); 

if($result) {
  if(!$test_on) 			 Header("Location:$cur_php?mode=stock_std_list&uDate=".$GR_Vals['uDate']."");	
exit;

}



} # end of insert

elseif($GR_Vals['mode_two']=="grp") {

 $skip_Array=array('no','mode_two');
 $qry_signal= make_qry($GR_Vals,$skip_Array,"skip_yes");

 $qry_stock_cmt_grp="update  `tbl_stock_cmt`   set  ".$qry_signal."  where no='".$GR_Vals['no']."'    ";

if($test_on)print_r($qry_stock_cmt_grp);
else  $result=mysqli_query($connect,$qry_stock_cmt_grp); 

if($result) {

  if(!$test_on) 			 {

                                         echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=stock_std_list\";
													 self.close();
												</script>	 
										";		  
  }

  }

exit;


}

elseif($GR_Vals['mode_two']=="ord_up") {

# $skip_Array=array('no','mode_two');
 #$qry_signal= make_qry($GR_Vals,$skip_Array,"skip_yes");
# $qry_stock_cmt_grp="update  `tbl_stock_cmt`   set  ".$qry_signal."  where no='".$GR_Vals['no']."'    ";

 
               # 이전 번호의 ord 번호를 찾아서..
			    $old_ord_no=$GR_Vals['ord_no']-1;
 				$query_ord="select no from tbl_stock_cmt where no='".$old_ord_no."' ";
				$result_ord=mysqli_query($connect,$query_ord);
				$ord_no= mysqli_fetch_array($result_ord); 

				$query_ord_no="update  `tbl_stock_cmt`   set  ord_no='".$GR_Vals['ord_no']."' where no='".$ord_no['no']."' ";
				if($test_on)print_r($query_ord_no);
                else  $result=mysqli_query($connect,$query_ord_no); 
				

                $query_ord_up="update  `tbl_stock_cmt`   set  ord_no='".$old_ord_no."' where no='".$GR_Vals['no']."' ";
				if($test_on)print_r($query_ord_up);
                else  $result_up=mysqli_query($connect,$query_ord_up); 			

if($result_up) {
  if(!$test_on) 			 {

                                         echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=stock_std_list\";
													 self.close();
												</script>	 
										";		  
  }

  }

exit;

} # end of ord_up






elseif($GR_Vals['mode_two']=="write") {


 				$query_cmt="select * from tbl_stock_cmt where no='".$GR_Vals['no']."' ";
				$result_cmt=mysqli_query($connect,$query_cmt);
				$cmt= mysqli_fetch_array($result_cmt);


    $ord_up="<a href='$cur_php?mode=stock_cmt&mode_two=ord_up&ord_no=".$cmt['ord_no']."&no=".$cmt['no']." '><img src='../img/u.gif'>순서 up</a>";

   echo $style_css;

										echo "
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

										echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:15px;' width=100% align=center border=0> "; # start of 1번째  tbl
										echo "<tr><td><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
																																																					<input type='hidden' name=mode value='stock_cmt'>
																																																					<input type='hidden' name=mode_two value='grp'>	
																																																					<input type='hidden' name=no value='".$GR_Vals['no']."'>	
												 </td></tr>";

												 echo "<Tr><td><input type=text name='cmt'  style='background-color:#EFF2FB;border-radius: 7px;font-weight:bold;border:dashed 1px gray;vertical-align:top;width:580px; height:30px;' value='".$cmt['cmt']."'>$ord_up</td></tr>";

										echo "<tr><td >";

														# 시작 :스마트 에디터 불러오기
														echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";
														echo"<textarea name=contents id=\"ir1\" style=\"width:99%; height:230px;display:none;\">".$cmt['contents']."</textarea>";

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


	                                	echo "<tr><td align=center> <input type=button value='등 록' onclick=\"javascript:submit_Confirm(document.myform);\" style='width:150px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'> </td> </tr>";

																				
										   echo  "</table> "; # start of 1번째  tbl


}



#################################################################
} # end of stock_cmt($connect)
#################################################################



#################################################################
function stock_std_list($connect) {   ##★★★★★★★★ 장중 체결상세, 매매 이유
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";
$test_on=0;

$GR_Vals=Get_Vals('mode');

$opt_deco=array('type'=>1);
$opt_deco2=array('type'=>31);
$opt_deco22=array('type'=>21);

#print_r($GR_Vals);



$span_num=13;


# 일별 리스트 가져오기
    $dta['qry']="SELECT uDate  FROM `tbl_daily_stock_vol`  group by uDate desc  limit 0,7";																																													
	$dta['cur_day']=$GR_Vals['uDate'];
	$dta['urls']="$cur_php?mode=stock_std_list&uDate=".$GR_Vals['all']."";
	$dta['tbl']=array('width'=>"100px;",'height'=>"40px;");
    $get_date_list= get_date_List($dta,$connect);
	 if(!$GR_Vals['uDate'])$GR_Vals['uDate'] = $get_date_list['today'];

$qry_stock_std_grp="SELECT  no  from `tbl_stock_trade_history_grp`  where uDate='".$GR_Vals['uDate']."' and stock_code='".$GR_Vals['stock_code']."' ";										
$result_stock_std_grp=mysqli_query($connect,$qry_stock_std_grp); 
$std_grp=mysqli_fetch_array($result_stock_std_grp);	    

if($GR_Vals['stock_code']) {
  if($std_grp)  $grp_wr="<a onclick=\"open_popUp('".$std_grp['no']."',2);\" style='cursor:hand;font-size:12px;' ><img src='../img/c7.gif' title='보기'>보기</a>";
  else $grp_wr="<a onclick=\"open_popUp(0,1);\" style='cursor:hand;font-size:12px;'><img src='../img/pen.gif' title='그래프 등록'>Grp</a>";
}

#	<input type=submit  value='등 록' style='width:40px;height:30px;cursor:hand;'>
#if($GR_Vals['stock_code']) {
		$tr_text_input= "<table border=0>
														    <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>	
														   <input type=\"hidden\" name=\"mode\" value=\"stock_std_ins\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update\">
														  <input type=\"hidden\" name=\"stock_code\" value=\"".$GR_Vals['stock_code']."\">
														  <input type=\"hidden\" name=\"uDate\" value=\"".$GR_Vals['uDate']."\">												

														  <input type=\"hidden\" name=\"find_uTime\">	
														  <tr height='30px;' style='vertical-align:top;'><td colspan=4>
														<input type=button  value='등록' style='width:40px;height:30px;cursor:hand;' onclick=\"javascript:submit_Confirm(document.myform2);\" style='cursor:hand;'> 
														<input type=checkbox name=get_stock>
														<textarea name='pti' style=\"vertical-align:top;width:400px; height:30px;\" $auto_clear_tag>당일체결상세(TR0110)</textarea>
														<textarea name='pti_today' style=\"vertical-align:top;width:170px; height:30px;\" $auto_clear_tag>거래내역(TR 0606)</textarea>
														$grp_wr
														</form></td></tr></table>
										";
#}



## 주식 투자 원칙 코멘트

$stock_tr_tit_array=array(1=>"매수",-1=>"매도");


$stock_cmt_tit_array=array(1=>"1분봉",5=>"5분봉",9=>"일봉",99=>"심리");
$stock_cmt_tags="<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:12px;'  align=left border=0><tr>";
foreach($stock_cmt_tit_array as $ct_no => $ct_value)$stock_cmt_tags.="<td width=60px;><input type=radio name='ctype' value=".$ct_no."> ".$ct_value."</td>";
$stock_cmt_tags.="</tr></table>";


$stock_tr_tags="<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:12px;'  align=left border=0><tr>";
foreach($stock_tr_tit_array as $tr_no => $tr_value)$stock_tr_tags.="<td width=60px;><input type=radio name='tRtype' value=".$tr_no."> ".$tr_value."</td>";
$stock_tr_tags.="</tr></table>";

		$tr_cmt_input= "<table border=0>
														    <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=my_cmt>	
														   <input type=\"hidden\" name=\"mode\" value=\"stock_cmt\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"insert\">
														  <input type=\"hidden\" name=\"uDate\" value=\"".$GR_Vals['uDate']."\">												

														  <tr height='30px;' style='vertical-align:top;'><td>
														  							<input type=text name='cmt'  $auto_clear_tag class=form_nc style='border-radius: 7px;font-weight:bold;border:dashed 1px gray;vertical-align:top;width:200px; height:30px;'>
																					</td>
																					<Td>".$stock_cmt_tags."</td>
																					<Td>".$stock_tr_tags."</td>
																					
																					<td>
														 <input type=submit  value='등록' style='width:40px;height:30px;cursor:hand;' border-radius: 7px;font-weight:bold;border:dashed 1px gray;vertical-align:top;cursor:hand;'> 							
														</form></td></tr>
									</table>
										";

    $today_tr_cmt="<Table style='line-height:200%' width=98%>";

	$today_tr_cmt.="<tr><td style='font-weight:bold;' colspan=3>< 소리내어 읽기 ></td></tr>";
	

   			 $max_width=600;

			 $qry_stock_cmt="SELECT  * from `tbl_stock_cmt` order by ord_no   limit 0,10 ";										
			 $result_stock_cmt=mysqli_query($connect,$qry_stock_cmt); 

			 foreach($result_stock_cmt as $c_no => $c_value){

				   $stock_tr_img="";
				 if($c_value['tRtype']>0) $stock_tr_img="<img src=../img/buy.png>";
				 elseif($c_value['tRtype']<0) $stock_tr_img="<img src=../img/sell.png>";

                 $urls="prj_yehior.php?mode=img_pop&type=cmt_grp&no=".$c_value['no']."";
                 $contents=base64_Img_decode($urls,$c_value['contents'],$max_width);

			$today_tr_cmt.="<tr style='font-size:13.5px;'><td width=70px;>(#".intval($c_value['no']).") <a onclick=\"open_popUp('".$c_value['no']."',3)\" style='cursor:hand;'>".$stock_cmt_tit_array[$c_value['ctype']]."</td><td  width=30px;>".$stock_tr_img."</td><td>".$c_value['cmt']."</td></tr>";
			$today_tr_cmt.="<tr><td></td><td colspan=2>".$contents."</td></tr>";

			 }

	$today_tr_cmt.="</table>";


# 주식 투자 원칙 코멘트


#신규종목 등록,  검색 기준
$std_cookie=($_COOKIE['std_list']);
if($GR_Vals['limit_vals'])    { setcookie('std_list[limit_vals]',$GR_Vals['limit_vals'],time()+12800,'/');  $std_cookie['limit_vals']=$GR_Vals['limit_vals']; }
if(!$std_cookie['limit_vals']) $std_cookie['limit_vals']=5;

$std_limit_array=array(0,1,5,10,20,30,50);
    $std_limit_tags="<table style='font-size:12px;'><Tr><td width=10px;></tD><td> *조건: 1분매매대금(억) </td>";
for($sl=1;$sl<count($std_limit_array);$sl++) {

   if($std_cookie['limit_vals']==$std_limit_array[$sl]) 	$std_limit_tags.="<td width=30px;><img src='../img/check_on.gif'> ".$std_limit_array[$sl]."</td>";
	else $std_limit_tags.="<td width=30px;><img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=stock_std_list&uDate=".$GR_Vals['uDate']."&stock_code=".$GR_Vals['stock_code']."&limit_vals=".$std_limit_array[$sl]."'\" style='cursor:hand;'> ".$std_limit_array[$sl]."</td>";

}
    $std_limit_tags.="</tr></table>";

if($std_cookie['limit_vals']) $today_open_tag="<img src='../img/check_on.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?$dft_vals&uDate=".$GR_Vals['uDate']."&today_open=off'\" style='cursor:hand;'> ";
else  $today_open_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?$dft_vals&uDate=".$GR_Vals['uDate']."&today_open=on'\" style='cursor:hand;'> ";

			#			


			 $qry_stock_std_gr="SELECT  stock_code, count(*) as tot, count(case when tr_cap>=".$std_cookie['limit_vals']." then 1 end) as limit_cnt,  count(case when tr_buy_price>0 then 1 end) as tr_buy_cnt  from `tbl_stock_trade_detail`  where uDate='".$GR_Vals['uDate']."' group by stock_code  ";										
			 $result_stock_std_gr=mysqli_query($connect,$qry_stock_std_gr); 


if($result_stock_std_gr) {
												 foreach($result_stock_std_gr as $g_no => $gr_value){

													 $gr_view_tags[$gr_value['stock_code']] = "<a href='$cur_php?mode=stock_std_list&stock_code=".$gr_value['stock_code']."&uDate=".$GR_Vals['uDate']."'\" style='cursor:hand;font-weight:bold;color:red;font-size:14px;'>";

                                                    $ins_tr_cnt="";
                                                   if($gr_value['tr_buy_cnt']>0) $ins_tr_cnt="/#".$gr_value['tr_buy_cnt']."";

													   $std_tot_tags[$gr_value['stock_code']] ="(".$gr_value['limit_cnt']."/".$gr_value['tot']."".$ins_tr_cnt.")";

													 $std_stock_name_array[]=$gr_value['stock_code'];


													 # 그래프 등록여부
													  $qry_stock_std_grp="SELECT  no  from `tbl_stock_trade_history_grp`  where uDate='".$GR_Vals['uDate']."' and stock_code='".$gr_value['stock_code']."'  ";										
																$result_stock_std_grp=mysqli_query($connect,$qry_stock_std_grp); 
																if($result_stock_std_grp) {
																	 foreach($result_stock_std_grp as $p_no => $grp_value){
																		 $grp_view_tags[$gr_value['stock_code']] = "<a onclick=\"open_popUp('".$grp_value['no']."',2);\" style='cursor:hand;font-size:12px;' ><img src='../img/c7.gif' title='보기'></a> ";
																	 }
																}
																# 그래프 등록여부


												 }
}


 $qry_tr_stock="SELECT stock_code,no,sum(profit) as tot_profit,sum(sell_cap) as tot_sell_cap from `tbl_trade_review`  where sell_Date='".$GR_Vals['uDate']."' group by stock_code order by tot_sell_cap desc" ;
 $result_tr_stock=mysqli_query($connect,$qry_tr_stock); 

 # 매매 종목 상단 리스트

if($result_tr_stock) {

           $ggn=5;
           $wth_num="100/".$ggn;

	        $tot_tr_cnt=mysqli_num_rows($result_tr_stock);	


			$stock_tr_list="<table style='border: 1px dashed orange; border-radius: 10px; background-color:white; border-spacing:0px;padding:5px;font-size:12px;'  border=0 width=100%><tr height=30px;>";

										foreach($result_tr_stock as $t_no => $tr_value){

																				$std_wr="";

																			$stock_tr_name_array[]=$tr_value['stock_code'];

																			$stock_tot_sell_cap+=$tr_value['tot_sell_cap'];
																		    $stock_tot_profit+=$tr_value['tot_profit'];

																			$stock_info=get_stock_info($tr_value['stock_code'],$connect);
																			$stock_name=shorten_Str($stock_info['stock_name'],6,'');

																			if($GR_Vals['stock_code']==$tr_value['stock_code']) {


																				$vals=array('type'=>'trade_multi_view','stock_code'=>$tr_value['stock_code'],'uDate'=>$GR_Vals['uDate'],'detail'=>1);
																				$get_stock_tr=get_daily_tr_info($vals,$connect);

																			   $stock_tr_profit_tags="<tr align=center style=' background-color:yellow;font-size:14px; ' ><td colspan=".$span_num.">".$get_stock_tr['tag']."</td></tr>										   										   ";
																			   $stock_tit="<a href=\"$cur_php?mode=stock_std_ins&stock_name=".$stock_info['stock_name']."&stock_code=".$tr_value['stock_code']."&uDate=".$GR_Vals['uDate']."\" style='color:black;'>".$stock_info['stock_name']."</a>";

																			   $gr_view_tags[$tr_value['stock_code']]="<font style='color:blue;font-weight:bold;font-size:15px;'>";

																			}

																			 $mode_no=$t_no%$ggn;								 
																			 if($mode_no==0 and $t_no>1) $stock_tr_list.= "</tr><Tr height=30px;>";

																			 if(!$std_tot_tags[$tr_value['stock_code']]) $std_wr="<img src='../img/pen.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=stock_std_ins&stock_name=".$stock_info['stock_name']."&stock_code=".$tr_value['stock_code']."&uDate=".$GR_Vals['uDate']."'\" style='cursor:hand;'>";

																			$stock_tr_list.="<td width=".$wth_num."%>".$gr_view_tags[$tr_value['stock_code']].$stock_name."</a>(".deco_txt($tr_value['tot_profit'],133,0).") ".$std_wr."<br>".$grp_view_tags[$tr_value['stock_code']].$std_tot_tags[$tr_value['stock_code']]."</td>";




										}
										
										if($stock_tot_sell_cap)  $stock_tr_list.="<tr>".$dot_line."</tr><tr height=30px;><td colspan=$ggn><매매금액> ".deco_txt($stock_tot_sell_cap,3,0)." &nbsp;  <손익> ".deco_txt($stock_tot_profit,1,0)."</td></tr>";


										
										$stock_tr_list.="</table>";

}

# 체결과 상관없이 등록된 종목 리스트

			$stock_new_list="<table style='border: 1px dashed orange; border-radius: 10px; background-color:white; border-spacing:0px;padding:5px;font-size:12px;'  border=0><tr>";		
if($std_stock_name_array ) {
													   foreach($std_stock_name_array as $std_no => $std_value){

														 #  echo $std_value;
														 
														 if($stock_tr_name_array)  $srch_stock=in_array($std_value, $stock_tr_name_array);
													#	 else $srch_stock=0;

																	$stock_std_info=get_stock_info($std_value,$connect);
																	$stock_std_name=shorten_Str($stock_std_info['stock_name'],6,'');
																														   
														   if(empty($srch_stock)) {		
															     															       $mode_no=$std_nn%6;								 
																    if($mode_no==0 and $std_nn>1) $stock_new_list.= "</tr><Tr>";																	
																	
																	if($GR_Vals['stock_code']==$std_value) {  $stock_tit="<a href=\"$cur_php?mode=stock_std_ins&stock_name=".$stock_std_info['stock_name']."&stock_code=".$std_value."&uDate=".$GR_Vals['uDate']."\" style='color:black;'>".$stock_std_info['stock_name']."</a>";			  

																	$gr_view_tags[$std_value]="<font style='font-size:14px;font-weight:bold;color:blue;'>";																                        

																	$del_tags[$std_value]="<img src='../img/ic/12-em-cross.png' onclick=\"location.href='$cur_php?mode=stock_std_cmt&mode_two=del&stock_code=".$std_value."&uDate=".$GR_Vals['uDate']."'\" style='cursor:hand;'>";
																	
																	}

																	$stock_new_list.="<td width=115px;>".$gr_view_tags[$std_value].$stock_std_name."</a>"."<br>".$grp_view_tags[$std_value].$std_tot_tags[$std_value]." ".$del_tags[$std_value]."</td>";

																	$std_nn++;
																	
																	}			   
																	
													   }
}

			$stock_new_list.="</tr></table>";

			 $qry_stock_std="SELECT  * from `tbl_stock_trade_detail`  where stock_code=".$GR_Vals['stock_code']." and uDate='".$GR_Vals['uDate']."' and (  tr_cap>=".$std_cookie['limit_vals']."   or tr_sell_price>0 or tr_buy_price>0  or find_uTime>0 )  order by uTime  ";										
			 $result_stock_std=mysqli_query($connect,$qry_stock_std); 
			 if($result_stock_std) $tot_cnt=mysqli_num_rows($result_stock_std);	

			 #echo $qry_stock_std;


# 시그널 select

$std_signal_db_array=array("price_box","max_vol","price_up","price_sup","price_dn","price_bottom","price_fst_up");
$std_signal_tit_array=array("가격박스","최고거래량","돌파","지지","이탈","음봉","첫봉H돌파");
$std_signal_img_array=array("subj.gif","star_red.gif","bul_up_red2.gif","list.gif","bul_dn_blue2.gif","bul_dn_blue.gif","bul_up_red.gif");


$std_signal_tags="<a name='top_hdr'><table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  align=left border=0><tr>
                                                        	<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform_signal>	
														   <input type=\"hidden\" name=\"mode\" value=\"stock_std_cmt\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update_signal\">
														  <input type='hidden' name='no'  id='cmt_no'> 
														  <td >
														  <input type='text'  id='cmt_uTime' size='2' readonly onclick=\"submit_signal(document.myform_signal);\" style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB;cursor:hand'>
														  </td>														  
														  ";


for($sa=0;$sa<count($std_signal_tit_array);$sa++) {

 	$std_signal_tags.="<td width=100px;><input type=checkbox name=".$std_signal_db_array[$sa]." value=1> ".$std_signal_tit_array[$sa]."</td>";

}
 	$std_signal_tags.="</form></tr></table>";

   $stock_new_ins="<table style='font-size:14px;' border=0><tr>
										<td>													  										  
										  </td><td><img src='../img/pen.gif'><a href='prj_yehior.php?mode=tthai&go=1'>매매등록</a> (#".$tot_tr_cnt.")</tD><td>".$std_limit_tags."</td></tr></table>";

 # 메인테이블
  $std_tags=" <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  width=98%; align=center border=0>"; 

  $std_tags.= "<Tr><td   style='border: 0px dashed orange; border-radius: 6px; background-color:#F8E0EC; border-spacing:3px;font-size:14px;'colspan=".$span_num.">".$get_date_list['tags']."</td></tr>";

  $std_tags.="<tr><td colspan=$span_num>".$stock_new_ins."</td></tr>";

  $std_tags.="<tr><td colspan=$span_num>".$stock_tr_list."</td></tr>";
# $std_tags.="<tr>".$dot_line."</tr>";
 $std_tags.="<tr><td colspan=$span_num>".$stock_new_list."</td></tr>";


if($stock_tit)  $std_tags.= "<tr align=center style=' background-color:yellow;font-size:30px; font-weight:bold;' height=30px; ><td colspan=$span_num>".$stock_tit." (#".$tot_cnt.")</td></tr>";
  $std_tags.= $stock_tr_profit_tags;

   $std_tags.="<tr><td colspan=$span_num>".$tr_text_input."</td></tr>"; #자료입력

   $std_tags.="<tr><td colspan=$span_num>".$std_signal_tags."</td></tr>"; #시그널입력


# 주요저항과 지지선의 수량
$updn_array=array('uDate'=>$GR_Vals['uDate'],'stock_code'=>$GR_Vals['stock_code'],'disp'=>"std_list");
$updn_uTime=get_updn_info($updn_array,$connect);

$stock_updn_array=array("저항","지지");
$std_updn_tags="<a name='top_hdr'><table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  align=left border=0><tr>
                                                        	<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform_updn>	
															
														   <input type=\"hidden\" name=\"mode\" value=\"stock_std_cmt\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update_updn\">
														  <input type='hidden' name='no'  id='cmt_no2'>
														  <td >
														  
														  <input type='text'  id='cmt_uTime2' size='2' readonly onclick=\"submit_updn(document.myform_updn);\" style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB;cursor:hand'>
														  </td>

														  <td>
														  <input type=\"radio\" name=\"updn\" value=\"1\">".$stock_updn_array[0]."
  														  <input type=\"radio\" name=\"updn\" value=\"-1\">".$stock_updn_array[1]."
														  <input type='text' name='updn_price'    id='stock_price' value='기준가격' size='8'  class=form_nc $auto_clear_tag style='border-radius: 7px;font-weight:bold;font-size:15px;border:dashed 1px gray;'>원 &nbsp; 
														  <input type='text' name='updn_qty'    id='qty' value='수량' size='8'  class=form_nc $auto_clear_tag style='border-radius: 7px;font-weight:bold;font-size:15px;border:dashed 1px gray;'> 주
														  &nbsp;

														   <input type='text' name='updn_sell_qty'    id='sell_qty' value='총매도 수량' size='9'  class=form_nc $auto_clear_tag style='border-radius: 7px;font-weight:bold;font-size:15px;border:dashed 1px gray;'> 주 &nbsp;
														   	<input type='text' name='updn_buy_qty'    id='buy_qty' value='총매수 수량' size='9'  class=form_nc $auto_clear_tag style='border-radius: 7px;font-weight:bold;font-size:15px;border:dashed 1px gray;'> 주
														  
														  </td>

														  </tr></table>
														  
														  ";
  $std_tags.="<tr><td colspan=$span_num>".$std_updn_tags."</td></tr>"; # 주요 저항과 지지 수량


   $std_tags.= "<tr >".$dot_line."</tr>";

  $std_tags.="<tr align=center style=' background-color:yellow;font-size:15px; ' height=30px;>
  <td width=30px;>시간</td>
  <td width=20px;>PB</td>
  <td width=80px;>매도량</td>
  <td  width=80px;>매수량</td>
  <td  width=80px;>순매수량</td>
  <td  width=60px;>거래량</td>
  <td  width=80px;>누적</td>
  <td  width=60px;>순대금(억)</td>
  <td width=60px;>등락률</td>
  <td colspan=2>매수가격</td>
  <td colspan=2>매도가격</td>
  </tr>";


#
echo "<html><body>";
echo $style_css;

echo ("
					   <script type=\"text/javascript\">

													 function     submit_Confirm(v) { // 종목코드를 받아서 넘김
																																													// for(loop = 0; loop < v.length; loop++) {
																																													//   alert(v[loop].name+ '==>' + v[loop].value);
																																													//					}
																																														
																																														if(v.stock_code.value=='' || v.get_stock.checked) {

																																														  get_stock_code= prompt('종목코드');
																																														     if(get_stock_code==null) { 																																														
																																																   return;
																																																}
																																																v.stock_code.value= get_stock_code;		
																																																v.find_uTime.value=1;
																																														}

																																														//alert(v.stock_code.value);
																																														//return;

																																														   v.submit();
																						}


	                                          		 function     submit_cmt(no) { // 종목코드를 받아서 넘김
																																													
																																													// alert(no);																																								
																																												
																																														  get_cmt= prompt('코멘트');																																														   
																																														   if(get_cmt==null) { 																																														
																																																   return;
																																																}

																																														   go_to_urls_d4  ='$cur_php?mode=stock_std_cmt&mode_two=update&no='+no+'&cmt='+get_cmt+'';         
																																			                                              window.open(go_to_urls_d4, 'news_d4');
																																															
																																														//alert(go_to_urls_d4);
																																														//return;

																																														   v.submit();
																						}


		                                         	 function     submit_signal(v) { // 종목코드를 받아서 넘김
																																													
																																												//	 for(loop = 0; loop < v.length; loop++) {
																																												//	   alert(v[loop].name+ '==>' + v[loop].value);
																																												//						}																																														
																																													
																																													//	return;
																																														   v.submit();
																						}


		                                         	 function     submit_updn(v) { // 종목코드를 받아서 넘김
																																													
																																												//	 for(loop = 0; loop < v.length; loop++) {
																																												//	   alert(v[loop].name+ '==>' + v[loop].value);
																																												//						}																																														
																																													
																																													//	return;
																																														   v.submit();
																						}



	                                          		 function     input_data(cmt_no,cmt_uTime) { // 종목코드를 받아서 넘김													
																									

																																													  document.getElementById('cmt_no').value=cmt_no;
																																													  document.getElementById('cmt_uTime').value=cmt_uTime;

																																													  document.getElementById('cmt_no2').value=cmt_no;
																																													   document.getElementById('cmt_uTime2').value=cmt_uTime;

																																													  location.href='#top_hdr';																																												
																																													
																						}

			  				        function      open_popUp(no,opt) {

										stock_code_Vals='".$GR_Vals['stock_code']."';
										uDate_Vals='".$GR_Vals['uDate']."';
										

												                                      wth=3800;
																					  hgt=1300;	

																					  popupY=100;
																			  	      popupX=300;		

												 var popupX = (window.screen.width / 2) ;
												var popupY= (window.screen.height / 2) - (1000 / 2);

                                                  if(opt==1)           {  var url ='$cur_php?mode=stock_std_cmt&mode_two=write&stock_code='+stock_code_Vals+'&uDate='+uDate_Vals;	  }                                
												  else if(opt==11) {  var url ='$cur_php?mode=stock_std_cmt&mode_two=write&no='+no+'&stock_code='+stock_code_Vals+'&uDate='+uDate_Vals;	 }                
												  else if(opt==2)   {  var url ='$cur_php?mode=stock_std_cmt&mode_two=grp_view&grp_no='+no;	  }                                
  												  else if(opt==21)   {  var url ='$cur_php?mode=stock_std_cmt&mode_two=grp_view&no='+no;
												   wth=1000;
												    hgt=800;
												  
												  }                                
                                             	  else if(opt==3)   {  var url ='$cur_php?mode=stock_cmt&mode_two=write&no='+no;	 
																					 wth=700;
																					  hgt=400;
																					}          
																																																																			 
																																			 var size ='width='+wth+',height='+hgt+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 

																																			 opt_win=opt+'_win';
																																																																					
																																			 var n=open(url,opt_win,size); 

																																			   n.focus(); 		
																									
																																	} // end of fnc ::: 

			</script>

");


  
if(!$result_stock_std) {
	echo $std_tags;  
    echo "<tr><td colspan=$span_num>".$tr_cmt_input."</td></tr>"; 
	echo "<tr><td colspan=$span_num>".$today_tr_cmt."</td></tr>"; 

exit;
}


foreach($result_stock_std as $p_no => $std_value){

						 if($std_value['tr_buy_price']) {    $tr_buy_price=deco_txt($std_value['tr_buy_price'],3,0);
																				$tr_buy_qty=" (".deco_txt($std_value['tr_buy_qty'],3,0).") ";
																			 }
						 else { 
										   $tr_buy_price="";
										   $tr_buy_qty=""; 
						 }


						 if($std_value['tr_sell_price']) {  $tr_sell_price=deco_txt($std_value['tr_sell_price'],3,0);
																			  $tr_sell_qty=" (".deco_txt($std_value['tr_sell_qty'],3,0).") ";
																			 }
						 else { 
										   $tr_sell_price="";
										   $tr_sell_qty=""; 
						 }


  # 매매 코멘트가 있다면.. 가져올것
 $std_tr_cmt="";
 $std_tr_pb="";
 $find_uTime_vals="";

  			 $qry_stock_std_cmt="SELECT  cmt,no from `tbl_stock_trade_history_cmt`  where stock_code=".$std_value['stock_code']." and uDate='".$std_value['uDate']."' and  uTime=".$std_value['uTime']." order by no";										
			 $result_stock_std_cmt=mysqli_query($connect,$qry_stock_std_cmt); 


			 if($result_stock_std_cmt) { 
				                                                 $std_tr_cmt="<table style='font-size:12px;'>";
															foreach($result_stock_std_cmt as $c_no => $cmt_value){
														      	if($cmt_value['cmt'])$std_tr_cmt.=" <tr><td width=10px;></td><td><img src='../img/c7.gif'> </td><td> <a onclick=\"open_popUp('".$cmt_value['no']."',21);\" style='cursor:hand;font-size:12px;' >".$cmt_value['cmt']."</a></td></tr>";														
															}
															$std_tr_cmt.="</table>";
			 }

              $qry_stock_std_signal="SELECT  * from `tbl_stock_trade_history_signal`  where stock_code=".$std_value['stock_code']." and uDate='".$std_value['uDate']."' and  uTime=".$std_value['uTime']." order by no";										
			 $result_stock_std_signal=mysqli_query($connect,$qry_stock_std_signal); 
            if($result_stock_std_signal) { 
				
				                                                        $std_signal=mysqli_fetch_array($result_stock_std_signal);		

																		   if($std_signal['find_uTime']) $find_uTime_vals=1 ;

																	        for($ssd=0;$ssd<count($std_signal_db_array);$ssd++) 
																							 if($std_signal[$std_signal_db_array[$ssd]]) $std_tr_pb.="<img src='../img/".$std_signal_img_array[$ssd]."' title='".$std_signal_tit_array[$ssd]."'>";														
                                                  
															   }

$tr_vol_cap_bg="";
 
					  if($std_value['tr_cap']>10) $tr_vol_cap_bg="background-color:yellow;";

					  if($std_value['tr_rate']<0) $std_value['tr_cap']*=-1;
                      $net_vol_cap_str=cur_deco_txt($opt_deco2,$std_value['tr_cap'],10,10,10);

					  # 최대 거래량 찾기
					  $v++;
					  

					  if(1) {
												  $vs_tr_cap[$v]=abs($std_value['tr_cap']);
												  $max_tr_cap[$v]=max($vs_tr_cap);
												
												  #최대 매도량 찾기

												  $vs_sell_qty[$v]=abs($std_value['sell_qty']);
												  $max_sell_qty[$v]=max($vs_sell_qty);
												  if($v>1 and $vs_sell_qty[$v]>=$max_sell_qty[$v]) $cur_max_sell_style="<font style='font-weight:bold;background-color:white;'>";
												  else $cur_max_sell_style="";


												  #최대 매수량 찾기
												  $vs_buy_qty[$v]=abs($std_value['buy_qty']);
												  $max_buy_qty[$v]=max($vs_buy_qty);

												  if($v>1) {

													if($vs_tr_cap[$v]>=$max_tr_cap[$v]) $cur_max_style="<font style='color:white;font-weight:bold;background-color:red;'>";
													else $cur_max_style="";

												  if($vs_sell_qty[$v]>=$max_sell_qty[$v]) $cur_max_sell_style="style='color:white;font-weight:bold;background-color:blue;'";
												  else $cur_max_sell_style="style='color:blue;'";

												  if($vs_buy_qty[$v]>=$max_buy_qty[$v]) $cur_max_buy_style="style='color:white;font-weight:bold;background-color:red;'";
												  else $cur_max_buy_style="style='color:red;'";

												  }

					  }




					  $tr_price_rate=cur_deco_txt($opt_deco,$std_value['tr_rate'],1,3,-3);

					  if($std_value['tr_sell_price']>0 or $std_value['tr_buy_price']>0 ) $tr_vol_cap_bg="background-color:#F8E0F7;";

					  $hm_str=substr($std_value['uTime'],0,2).":".substr($std_value['uTime'], 2,2);
					

  					  if($find_uTime_vals) $hm_str_dp= $hm_str."<br><img src='../img/n2.gif'>"; 					 else $hm_str_dp=$hm_str;
					  $insert_cmt_hm_tags="<a onclick=\"open_popUp('".$std_value['no']."',11);\" style='cursor:hand;'>".$hm_str_dp."</a>";

					  #

$prv_vol_cap= $std_value['vol_cap'];
$prv_tr_price= $std_value['tr_price'];


$up_signal_tags="<a onclick=\"input_data('".$std_value['no']."','".$hm_str."');\" style='cursor:hand;'>";

  $std_tags.="
  <tr height=35px; style='text-align:right; ".$tr_vol_cap_bg."'>
  <td rowspan=2 align=center>".$insert_cmt_hm_tags."</td>
  <td>".$std_tr_pb."</td>
  <td ".$cur_max_sell_style.">".number_format(-$std_value['sell_qty'])."</td>
  <td ".$cur_max_buy_style.">".number_format($std_value['buy_qty'])."</td>
  <td>".deco_txt($std_value['net_qty'],1,0)."</td>
  <td>".$cur_max_style.deco_txt($std_value['sell_qty']+$std_value['buy_qty'],3,0)."</font><br>".cur_deco_txt($opt_deco22,$std_value['buy_qty']/$std_value['sell_qty'],2,10,3)."</td>

  <td>".deco_txt($std_value['net_qty_acc'],1,0)."</td>
  <td>".$up_signal_tags.$net_vol_cap_str."</td>
  <td>".$tr_price_rate."<br>".$std_value['tr_rate_vs']."</td> 
  <td>".$tr_buy_price."</td>
  <td align=center width=30px;>".$tr_buy_qty."</td>
  <td>".$tr_sell_price."</td>
  <td align=center width=30px;>".$tr_sell_qty."</td>
  </tr>";
$std_tags.="<tr style='".$tr_vol_cap_bg."'><td></td><td colspan=$span_num style='line-height:190%'>".$std_tr_cmt."</td></tr>";

# 저항과 지지 수량
if($updn_uTime[$std_value['uTime']])  $std_tags.="<tr style='".$tr_vol_cap_bg."'><td colspan=2></td><td colspan=$span_num style='line-height:190%'><table  style='font-size:12px;'><tr>".$updn_uTime[$std_value['uTime']]."</tr></table></td></tr>";
  
  $std_tags.="<tr>$dot_line</tr>";

}

echo $std_tags;

echo "</body></html>";

#if($result_ins) {
	#		 Header("Location:$cur_php?mode=ref_list");
		     
	 #       }

	 

#################################################################
} # end stock_pt_list($connect)
#################################################################



#################################################################
function get_updn_info($updn_array,$connect) {  # 저항지지 가격 
#################################################################

   $uDate=$updn_array['uDate'];
   $stock_code=$updn_array['stock_code'];
   $disp=$updn_array['disp'];


   if($disp=="std_list") {   
											$qry_stock_updn="SELECT  *  from `tbl_stock_trade_updn`  where uDate='".$uDate."' and stock_code='".$stock_code."' ";										
									   }

   elseif($disp=="top_pi_list") {   
											$qry_stock_updn="SELECT  *  from `tbl_stock_trade_updn`  where uDate<='".$uDate."' and  stock_code='".$stock_code."' order by uDate,uTime  limit 0,100";										
											
								    	   }



$result_stock_updn=mysqli_query($connect,$qry_stock_updn); 

 foreach($result_stock_updn as $updn_no => $updn_value){

	          if($updn_value['updn']==1) $up_tags[$updn_value['uTime']]="<Td width=30px;></td><td  width=140px;> <img src='../img/bul_up_red.gif'> ".deco_txt($updn_value['updn_price'],3,0)."원 (".deco_txt($updn_value['updn_qty'],3,0)."주)</td><Td width=30px;></td>";
              else $dn_tags[$updn_value['uTime']]="<td width=140px;> <img src='../img/bul_dn_blue.gif'> ".deco_txt($updn_value['updn_price'],3,0)."원 (".deco_txt($updn_value['updn_qty'],3,0)."주)</td><Td width=30px;></td>";

			    if($updn_value['updn_sell_qty']) $qty_plus_tags[$updn_value['uTime']]= "<Td width=30px;><td><img src='../img/up_arr.gif'> x배 <font style='font-size:20px;color:black;font-weight:bold;text-decoration:underline;'>".deco_txt($updn_value['updn_sell_qty']/$updn_value['updn_buy_qty'],31,0)."</td>";



   if($disp=="std_list") {  
	   
                                     	   if(!$up_tags[$updn_value['uTime']]) $up_tags[$updn_value['uTime']]="<Td width=30px;></td><td   width=140px;> </td><Td width=30px;> </td>";
										   if(!$dn_tags[$updn_value['uTime']]) $dn_tags[$updn_value['uTime']]="<Td width=140px;> </td><Td width=30px;></td>";

										   $updn_uTime[$updn_value['uTime']]=$up_tags[$updn_value['uTime']].$dn_tags[$updn_value['uTime']].$qty_plus_tags[$updn_value['uTime']];										
									   }

   elseif($disp=="top_pi_list") {


             if(!$up_tags[$updn_value['uTime']]) $up_tags[$updn_value['uTime']]="<Td width=30px;></td><td   width=150px;> </td><Td width=30px;> </td>";
                                    	   $updn_uTime_date[$updn_value['uDate']][$updn_value['uTime']]=$up_tags[$updn_value['uTime']].$dn_tags[$updn_value['uTime']];										  
				   }

   } # end of foreach


if($disp=="top_pi_list") {

   if($updn_uTime_date) {
												 foreach($updn_uTime_date as $uDate_no => $uDate_value){

													   $updn_uTime_tags="<table style='font-size:12px;'>";

													   foreach($uDate_value as $uTime_no => $uTime_value){

														   $hm_str=substr($uTime_no,0,2).":".substr($uTime_no, 2,2);

														   $updn_uTime_tags.="<tr><td>".$hm_str."</td>".$uTime_value."</tr>";
													   }

													 $updn_uTime_tags.="</table>";
													
													$updn_uTime[$updn_value['uDate']]=$updn_uTime_tags;
												 }

		   } # end of $updn_uTime_date

 }


return $updn_uTime;

#################################################################
} # end get_updn_info($connect)
#################################################################





#################################################################
function realtime_price_inserT($connect) {  # 
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$GR_Vals=Get_Vals('mode');

#$query_ins="insert into tbl_ref_memo set ref_title='$ref_title',ref_url='".$GR_Vals['ref_url']."' ,ref_cmt='".$GR_Vals['ref_cmt']."' ,ref_type='".$GR_Vals['ref_type']."'  " ;
#$result_ins=mysqli_query($connect,$query_ins); 


if($GR_Vals['mode_two']=='update') {


	   $as_vals=explode("\n",preg_replace("/[#\&\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['realtime_price'])); # 불필요한 특수문자들 제거후

#	   print_r($as_vals);


  # 기존 real_time 초기화

             $qry_stock_rate="update all_stock_info set  stock_rate_rt=0,stock_rate=0,stock_price=0,stock_high_price=0";
  		     $result=mysqli_query($connect, $qry_stock_rate); 


	   	   foreach($as_vals as $si_key=>$si_array)   { 

			   if($si_key<1) continue;

								   $stock_price_array=explode("\t",$si_array);   # 당일 매매 내역을 종목별로 배열에 할당

								#   print_r($stock_price_array);

								  # print_r($stock_price_array);

                                  #  조건검색:  당락률 상위 100   [0] => 종목코드     [1] => 종목명     [2] => 현재가    [3] => 등락률    [4] => 거래량    [5] => 거래대금(백만)
								  #   거래대금 => 억, 소수점2자리,  시가총액 억 => x10
#								      [0] => 종목코드    [1] => 종목명    [2] => 현재가    [3] => 등락률    [4] => 거래량    [5] => 고가   [9]=> 거래대금(백만)    [10] => 시가총액


                                      $stock_vol_cap= $stock_price_array[9]/100;  # 거래대금 백만 /100 => 억, 소수점두자리
                                      $stock_cap= $stock_price_array[10]*10;  #  시가총액  십억.00 *10 => 억

									#  $pre_stock_price=$stock_price_array[2]-$stock_price_array[7];

									#if($stock_price_array[3]>=0) { $stock_yrate= $stock_price_array[7];

									#echo $stock_price_array[3];

									#}
									#	else $stock_yrate=$stock_price_array[7]*(-1);


								  $qry_stock="update all_stock_info set  stock_price='".$stock_price_array[2]."', stock_yrate='".$stock_price_array[8]."', stock_high_price='".$stock_price_array[5]."', stock_rate='".$stock_price_array[3]."',   stock_rate_rt='".$stock_price_array[3]."',  stock_cap='".$stock_cap."' where stock_code='".$stock_price_array[0]."'";
				    	  	     # $result=mysqli_query($connect, $qry_stock); 
						
								#print_r($qry_stock);

								

		   }


                		         $target_key='price_rt_uDate';										   
								 $last_etf_update=data_upTime($target_key, 'update',$pdo);

#exit;
						 Header("Location:$cur_php?mode=thema_all");


}

else
	{
			$tr_text_input= "<table>
			                               <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
										   <input type=\"hidden\" name=\"mode\" value=\"realtime_pi\">
										  <input type=\"hidden\" name=\"mode_two\" value=\"update\">
												 <tr align=\"left\">
													 <td align='left' style='padding-top:15px;' colspan=4>     									
										
												<input type=submit value='등 록' class=form_nc style='width:80px;cursor:hand;'>                    							

												조건검색 :  상승종목_Update
																										
													 </td>";

				$tr_text_input.= "<tr><td colspan=4><textarea name=realtime_price style=\"width:755px; height:1812px;\"></textarea></form></td></tr></table>";

echo $tr_text_input;

	}








#if($result_ins) {
	#		 Header("Location:$cur_php?mode=ref_list");
		     
	 #       }


#################################################################
} # end of ref_updat($connect)
#################################################################


#################################################################
function top_price_inserT($connect, $pdo) {  # 당일 상승률 상위 종목
#################################################################
    global $cur_php;
    require_once "./env/inf.fnc";
    require_once "./env/e.fnc";

    $test_on = 0;
    $today = date("Y-m-d");
    $GR_Vals = Get_Vals('mode');

    if ($test_on == 2) { 
        print_r($GR_Vals); 
        exit; 
    }


    if ($GR_Vals['mode_two'] === 'update') {

      $target_key='top_pi';										   
 	  $last_etf_update=data_upTime($target_key, 'update',$pdo);

        // 2. 불필요한 특수문자 제거 후 배열 분리
        $as_vals = explode("\n", preg_replace("/[#\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['top_price'])); 
        if ($test_on) print_r($as_vals);

        // ==========================================================
        // 🚀 [STEP 1] 루프 바깥에서 쿼리 미리 준비 (속도/메모리 최적화)
        // ==========================================================
        
        // 일괄 초기화 (파라미터가 없거나 고정이면 exec, prepare 모두 무방)
        $pdo->exec("UPDATE all_stock_info SET stock_rate_rt = 0");
        
        $stmt_reset = $pdo->prepare("UPDATE tbl_daily_stock_vol SET up_On = 0, new_On = 0 WHERE uDate = :today");
        $stmt_reset->execute(['today' => $today]);

        // 반복문 안에서 쓸 쿼리 6개 장전
        $stmt_chk_top   = $pdo->prepare("SELECT no FROM tbl_daily_stock_vol WHERE stock_code = :code AND uDate = :today");
        $stmt_upd_rt    = $pdo->prepare("UPDATE all_stock_info SET stock_rate_rt = :rate, stock_rate = :rate2 WHERE stock_code = :code");
        $stmt_get_float = $pdo->prepare("SELECT stock_vol_float FROM all_stock_float WHERE stock_code = :code");
        $stmt_get_tot   = $pdo->prepare("SELECT stock_vol_tot FROM all_stock_info WHERE stock_code = :code"); // all_stock_info 함수 대체!
        $stmt_chk_tr    = $pdo->prepare("SELECT no, SUM(sell_cap) as tot_sell_cap FROM tbl_trade_review WHERE sell_Date = :today AND stock_code = :code");
        
        // UPDATE & INSERT 구문 준비 (속도를 위해 ? 위치 기반 바인딩 사용)
        $stmt_update = $pdo->prepare("UPDATE tbl_daily_stock_vol SET stock_price=?, stock_rate=?, stock_vol=?, stock_vol_cap=?, stock_vol_float=?, rank_now=?, up_On='1' WHERE no=?");
        $stmt_insert = $pdo->prepare("INSERT INTO tbl_daily_stock_vol (stock_price_first, stock_rate, stock_vol, stock_vol_cap, stock_code, stock_name, up_On, new_On, uDate, find_first_uDate, stock_vol_tot, stock_vol_float, rank_first, rank_now, today_tr_no, today_tr_cap) VALUES (?, ?, ?, ?, ?, ?, '1', '1', ?, NOW(), ?, ?, ?, ?, ?, ?)");

        // ==========================================================
        // 🚀 [STEP 2] 초고속 반복문 실행
        // ==========================================================
        
        $rf = 0; // 순위 카운터

        foreach ($as_vals as $si_key => $si_array) {
            $stock_price_array = explode("\t", $si_array);
            
            // 첫 줄(헤더) 검증
            if ($si_key < 1) { 
                if (trim($stock_price_array[12] ?? '') !== "거래대금") {
                    echo "error! 거래대금체크"; exit;
                }
                continue;
            }

            if (empty($stock_price_array[12])) continue;

            // 데이터 정제
            $stock_code = trim($stock_price_array[3]);
            $stock_name = trim($stock_price_array[4]);
            $stock_price = (float)$stock_price_array[5];
            $stock_price_rate = (float)$stock_price_array[8];
            $stock_vol = (float)$stock_price_array[11];
            $stock_vol_cap = (float)$stock_price_array[12] / 100;

            // 1. 기존 등록 여부 확인
            $stmt_chk_top->execute(['code' => $stock_code, 'today' => $today]);
            $get_stock_top = $stmt_chk_top->fetchColumn(); // PK(no)만 바로 빼옴

            // 2. 실시간 상승률 업데이트
            $stmt_upd_rt->execute(['rate' => $stock_price_rate, 'rate2' => $stock_price_rate, 'code' => $stock_code]);

            // 필터링: 상승률 7% 미만이거나 대금 20억 미만은 패스 (업데이트는 한 뒤에 패스하는 기존 로직 유지)
            if ($stock_price_rate < 7) continue; 
            if ($stock_vol_cap < 20) continue; 

            if ($test_on) { print_r($stock_price_array); }
            $rf++;

            // 3. 유통주식수 조회
            $stmt_get_float->execute(['code' => $stock_code]);
            $vol_float = $stmt_get_float->fetchColumn() ?: 0; // 없으면 0

            if ($get_stock_top) {
                // UPDATE 실행
                if ($test_on) { echo "UPDATE 대상: {$stock_code}<br>"; } 
                else {
                    $stmt_update->execute([$stock_price, $stock_price_rate, $stock_vol, $stock_vol_cap, $vol_float, $rf, $get_stock_top]);
                }
            } else {
                // INSERT를 위한 추가 정보 조회

                // 4. 총 상장주식수 개별 조회 (all_stock_info 대체)
                $stmt_get_tot->execute(['code' => $stock_code]);
                $vol_tot = $stmt_get_tot->fetchColumn() ?: 0;

                // 5. 당일 매매 내역(Trade Review) 조회
                $stmt_chk_tr->execute(['today' => $today, 'code' => $stock_code]);
                $tr_data = $stmt_chk_tr->fetch(PDO::FETCH_ASSOC);
                
                $tr_no = !empty($tr_data['no']) ? $tr_data['no'] : 0;
                $tr_cap = !empty($tr_data['tot_sell_cap']) ? $tr_data['tot_sell_cap'] : 0;

                // INSERT 실행
                if ($test_on) { echo "INSERT 대상: {$stock_code}<br>"; } 
                else {
                    $stmt_insert->execute([$stock_price, $stock_price_rate, $stock_vol, $stock_vol_cap, $stock_code, $stock_name, $today, $vol_tot, $vol_float, $rf, $rf, $tr_no, $tr_cap]);
                }
            }
        } // foreach 끝

        if (!$test_on) {
            header("Location: {$cur_php}?mode=top_pi_list");
            exit; // 🚨 Header 이동 후에는 반드시 exit을 걸어주는 것이 실무 보안 표준입니다.
        }
    }
#################################################################
} // top_price_inserT
#################################################################


#################################################################
function top_price_etc_list($connect) {  # 시간외, 장개시전 예상체결
#################################################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;

$cur_today = date("Y-m-d");
$GR_Vals=Get_Vals('mode');

#print_r($GR_Vals);

$qry_udate="SELECT uDate  FROM `tbl_daily_stock_etc`  where stock_type=1 group by uDate desc limit 0,1"; # 시간외
$result_uDate=mysqli_query($connect,$qry_udate); 
if($result_uDate)$get_uDate=mysqli_fetch_array($result_uDate);					   			 

      $min_rate=array(0,1,5,-90,-90); # 시간외 1%, 예상체결 5% 이상, 거래대금은 -10% 이상이면 보여줄것
	  $min_vol_cap=array(0,1,1,100,1); # 거래대금 1억이상
      $today=array(0,$get_uDate['uDate'],$cur_today,$cur_today,$cur_today);
	  $tit=array(0,"시간외(TR1304) ","예상체결(TR0183)","거래대금상위(TR0186) ","시총상위Top30(TR0187) ");
	  $sort_opt=array(0,"stock_rate desc","stock_rate desc","stock_vol_cap desc","ord_cur");
	  $limit_opt=array(0,"","","limit 0,30","limit 0,30"); # 거래대금은 상위 30개만 가져오기


if($GR_Vals['mode_two']=='update') { # start of mode_two update

 $as_vals=explode("\n",preg_replace("/[#\+\%@=\/\\\:;,\'\"\^`~\_|\!\?\*$#<>\[\]\{\}]/i", "", $GR_Vals['cts'])); # 불필요한 특수문자들 제거후
 
# if($test_on)  print_r($as_vals);

##   시간외:    [0]  순위     *[2]  종목코드    [3]  종목명    *[4]  현재가    [5]  전일대비    *[7]  등락률    [8]  매도잔량    [9]  매수잔량    [10]  (외)거래량    *[11]  거래대금    [12]  당일종가    [13]  당일종가등락률

##  예상체결   [0]  순위    *[1]  종목코드    [2]  종목명    *[3]  예상체결가    [4]  기준가격    [5]  대비       *[7]  등락률    [8]  예상체결량    [9]  매도잔량    [10]  매도호가    [11]  매수호가    [12]  매수잔량  

## 거래대금상위  [0]  순위 [1] 전일 *[2] 종목코드 [3] 종목명 *[4] 현재가 [5] 전일대비 [6] => *[7]  등락률 [8]  매도호가 [9] 매수호가 [10] 거래량 [11] 시가총액 *[12] 거래대금

#  시총상위   [0] 순위 *[1] 종목코드 [2] 종목명 *[3] 현재가 [4] 전일대비 [5] *[6] 등락률 [7] 대금(백만) [8] 거래비중 [9] 시가총액 [10] 시가총액비 [11] 체결강도

     $array_name[1] = array('stock_code'=>2,'stock_price'=>4,'stock_rate'=>7,'stock_cap'=>11); # 시간외
     $array_name[2] = array('stock_code'=>1,'stock_price'=>3,'stock_rate'=>7,'stock_cap'=>8); # 예상체결
     $array_name[3] = array('stock_code'=>2,'stock_price'=>4,'stock_rate'=>7,'stock_cap'=>12); # 대금상위
	  $array_name[4] = array('stock_code'=>1,'stock_price'=>3,'stock_rate'=>6,'stock_cap'=>7); # 시총상위

$ord_cur=1;

						 foreach($as_vals as $si_key=>$si_array)   { 

									   if($si_key<1) continue;

											   $stock_price_array=explode("\t",$si_array);  

											    if($test_on==1)  print_r($stock_price_array);

												$stock_code=$stock_price_array[$array_name[$GR_Vals['stock_type']]['stock_code']];
												$stock_price=$stock_price_array[$array_name[$GR_Vals['stock_type']]['stock_price']];
												$stock_rate=$stock_price_array[$array_name[$GR_Vals['stock_type']]['stock_rate']];
												$stock_cap=$stock_price_array[$array_name[$GR_Vals['stock_type']]['stock_cap']];
																												   

												 if(empty($stock_code)) continue;

											   
											    if($GR_Vals['stock_type']==2)  $stock_vol_cap=($stock_price*$stock_cap)/100000000; # 예상체결															
											   else $stock_vol_cap=$stock_cap/100; # 예상체결															

											   
															   
											    if($test_on==2)  print_r($stock_price_array);
											  
														$query_stock_etc="SELECT  * from `tbl_daily_stock_etc`  where stock_code='".$stock_code."' and  uDate='".$today[$GR_Vals['stock_type']]."' and  stock_type='". $GR_Vals['stock_type']."' ";
														$result_stock_etc=mysqli_query($connect,$query_stock_etc); 
														if($result_stock_etc)$get_stock_etc=mysqli_fetch_array($result_stock_etc);					   			 

															  if($test_on) { print_r($query_stock_etc);  echo "<br>"; }

														 if($get_stock_etc) {
															  $qry_stock="update  tbl_daily_stock_etc set  ord_prv='".$get_stock_etc['ord_cur']."',ord_cur='".$ord_cur."',  stock_price='".$stock_price."', stock_rate='".$stock_rate."', stock_vol_cap='".$stock_vol_cap."'  where no='".$get_stock_etc['no']."' ";
														 }
														 else	{
															           $qry_stock="insert  into  tbl_daily_stock_etc set ord_cur='".$ord_cur."', stock_price='".$stock_price."', stock_rate_first='".$stock_rate."', stock_rate='".$stock_rate."', stock_vol_cap='".$stock_vol_cap."', stock_code='".$stock_code."', stock_type='".$GR_Vals['stock_type']."' , uDate= '".$cur_today."' ";

																										   if( $stock_rate<$min_rate[$GR_Vals['stock_type']]) continue;
																										   if($stock_vol_cap<$min_vol_cap[$GR_Vals['stock_type']]) continue;

														 }
														 $ord_cur++;
														  
														  if($test_on==3) { print_r($qry_stock);  echo "<br>"; }
														  else $result_ins=mysqli_query($connect, $qry_stock); 

						 }

##############
} # end of mode_two update
##############


                    # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기





$opt_deco['type']=21;
$opt_deco['str']="%";
$opt_deco['font']="17px;";

for($st=1;$st<count($min_rate);$st++) {

$sn=0;
$thema_list=array();
$thema_stock_name=array();
$thema_rate=array();
$thema_stock_rate=array();


## 시작 :폼 만들기
					$tr_text_input[$st]= "<table border=0>
														   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
														   <input type=\"hidden\" name=\"mode\" value=\"top_pi_etc\">
														  <input type=\"hidden\" name=\"mode_two\" value=\"update\">
														  <input type=\"hidden\" name=\"stock_type\" value=\"".$st."\">
												
														<tr height='30px;' style='vertical-align:top;'><td colspan=4><input type=submit  value='".$tit[$st]."' style='width:250px;height:30px;cursor:hand;'> <textarea name='cts' style=\"vertical-align:top;width:405px; height:30px;\"></textarea></td></tr>
														 																	
															</table>
															</form>
													";

											# stock_type: 시간외
											 $query_stock="SELECT  * from `tbl_daily_stock_etc`  where uDate='".$today[$st]."' and stock_type=$st order by ".$sort_opt[$st]." ".$limit_opt[$st]." ";
											 $result_stock=mysqli_query($connect,$query_stock); 

											 #echo $query_stock;

											#종목과 연관된 테마 불러오기
											$get_thema_no=top_pi_get_thema_all($query_stock,$connect);

											 $stock_list_tag[$st]="<table style='font-size:14px;' border=0 width=98%>";

											 $stock_list_tag[$st].="<tr style='background-color:yellow;'><td colspan=2>no.</td><Td width=180px;>".$tit[$st]."</td><td width=130px; colspan=2 align=center>상승률</td><td width=70px;>대금</td><td width=200px;>테마 (".$today[$st].")</td></tr>";
 											 $stock_list_tag[$st].="<tr>".$dot_line."</tr>";

											foreach($result_stock as $s_no => $s_value){
											$sn++;
											$thema_tags="";

											  $get_stock_info=get_stock_info($s_value['stock_code'],$connect);

																																						   if($get_thema_no[$s_value['stock_code']]) {	

																																							   $rel_thema_no=$get_thema_no[$s_value['stock_code']];

																																								$thema_tags="<table><tr style='color:#298A08;font-size:15px;'>";
																																															 
																																																foreach(  $rel_thema_no as $t_no => $t_value) {	

																																																  $thema_name_str=$all_thema_name[$t_value]['thema_name'];

																																																  
																																																  $thema_list[$t_value][]="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10001&thema_no=$t_value&key_word=".$thema_name_str."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$thema_name_str."</a>";
																																																  $thema_stock_name[$t_value][]="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10002&stock_code=".$s_value['stock_code']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$get_stock_info['stock_name']."</a>";
																																																  $thema_stock_rate[$t_value][]=cur_deco_txt($opt_deco,$s_value['stock_rate'],5,1,10);
																																																																																						
																																																  $thema_tags.="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10001&thema_no=$t_value&key_word=".$thema_name_str."','pop_hidden','width=10, height=10');\" style='cursor:hand;color:#298A08;font-size:12px;'>".$thema_name_str."</br>";
																																																}
																																								$thema_tags.="</tr></table>";
																																							
																																						   }



												 if($s_value['ord_prv']>0) {

													    $ord_prv_tag=deco_txt($s_value['ord_prv']-$s_value['ord_cur'],122,0);;

														 }

														 else 
												{
															 $ord_prv_tag="<img src='../img/n2.gif'>";

												}




											  $stock_list_tag[$st].="<tr height=25px;><td >$sn</td><td width='40px;' align=center>".$ord_prv_tag."</td><td ><a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10002&stock_code=".$s_value['stock_code']."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>".$get_stock_info['stock_name']." </td><td align=right width=70px;>".cur_deco_txt($opt_deco,$s_value['stock_rate'],5,0,0)." </td><td width=65px; align=left>".deco_txt($s_value['stock_rate']-$s_value['stock_rate_first'],12,0)."</td><td align=right width=70px;>".deco_txt($s_value['stock_vol_cap'],133,500)." 억</td> <Td >".$thema_tags."</td></tr>
											  <tr>".$dot_line."</tr>

											  ";
											  
											}

											$stock_list_tag[$st].="</table>";


											## 테마 통계

											## 테마 리스트
											  arsort($thema_list);

																				 $tl_tags[$st]="<tr style='height:50px;' align=center><td colspan=11>
																					 <table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  width=100% align=center border=0>";


																				$stock_mod=5;
																				$stock_name_tag_width=($tbl_width['i4t']*0.95)/$stock_mod;

																		 
																				  foreach($thema_list as $tl_no => $tl_value){
																					
																						if(count($tl_value)>1) {
																										 $j++;
																										 $sj=0;

																										  $st_tags="<table><tr >";

																										 foreach($thema_stock_name[$tl_no] as $st_no => $st_value){
																											 $mode_no=$sj%$stock_mod;								 
																																		 if($mode_no==0 and $sj>1) $st_tags.= "</tr><Tr>";
																											 $st_tags.="<Td style='font-size:11px;color:#C9AFAF;padding-bottom: 1px;padding-top: 5px;' nowrap>".$st_value."</a>".$thema_stock_rate[$tl_no][$st_no]."</td>";
																											 $sj++;
																										 }
																										 $st_tags.="</tr></table>";

																									  $tl_tags[$st].= "<tr><td width='15px;'>#".count($tl_value)."</td><td width=125px;>".$tl_value[0]."  </td><td  style='padding-bottom: 5px;padding-top: 2px;font-size:12px;'>$st_tags</td></tr>"; 

																									  $tl_tags[$st].= "<tr>$dot_line</tr>";

																									  $two_num[$st]=1;

																										#  
																										#  
																								}
																				  }

																				 $tl_tags[$st].="</table></td></tr><tr height=15px;><td></td></tr>";
											  

}


echo "<html><body>";

  echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:13px;'  width=".($tbl_width['i4t']*0.96)." align=center border=0> "; # start of 1번째  tbl


 for($r=count($min_rate)-1;$r>0;$r--) {																														
	
							## 거래대금 상위
							   echo "<Tr><td   style='border: 0px dashed orange; border-radius: 6px; background-color:#F8E0EC; border-spacing:3px;font-size:14px;'colspan=12>".$tr_text_input[$r]."</td></tr>";
								  if($two_num[$r]) echo "<Tr><td   style='border: 0px dashed orange; border-radius: 6px; background-color:#F8E0EC; border-spacing:3px;font-size:14px;'colspan=12>".$tl_tags[$r]."</td></tr>";

								  echo "<Tr><td   style='border: 0px dashed orange; border-radius: 6px;  border-spacing:3px;font-size:14px;'colspan=12>".$stock_list_tag[$r]."</td></tr>";

							echo "<Tr height=10px;><td   style=colspan=12></td></tr>";

	  }


echo "</body></html>";

#################################################################
} # end of top_price_etc_list
#################################################################




#################################################################
function top_pi_get_stock_news_info($vals,$type,$connect) {  #  오늘 뉴스 배열로 가져오기 
#################################################################


                    # 시작 :전체 테마종목 가져오기
																										   $arr_thema_srch['qry']="select thema_no,thema_name from tbl_thema_name";																											
																										   $arr_thema_srch['keys'] ='thema_no';
																										   #$arr_thema_srch['multi_keys'] =0;
 
																											$thema_srch_array=php_mysql_Query($arr_thema_srch,$connect);
                                                                                                          # 테마 네임 배열
																											$all_thema_name=$thema_srch_array['multi_keys'];
                     # 끝 :전체 테마종목 가져오기





if($type=="day") {
		$query_stock_news['qry']="SELECT no,thema_no,rel_stock_info,news_title,news_link FROM `tbl_news_scrap` WHERE DATE_FORMAT(uDate,'%Y-%m-%d')='".$vals."' ";
}

elseif($type=="stock") {

	$query_stock_news['qry']="SELECT DATE_FORMAT(uDate,'%Y-%m-%d') as news_day,no,thema_no,news_title,news_link FROM `tbl_news_scrap` WHERE rel_stock_info LIKE '%".$vals."%' " ;

}

		$result_stock_news=php_mysql_query($query_stock_news,$connect); 


if($result_stock_news['value']) { # start of chk value
						
							foreach($result_stock_news['value'] as $pi_no => $pi_value){

								$news_tit= shorten_Str($pi_value['news_title'],39,'..');

																	 if($type=="day") { 
																											  $rel_stock_array=explode('@@',$pi_value['rel_stock_info']);

																														  for($r=0;$r<count($rel_stock_array);$r++) {																																																																				               
																																$rel_stock_info=explode('#',$rel_stock_array[$r]);

																															  if($pi_value['thema_no']>0) $thema_tags="<img src='../img/ico_thema.gif'> <font style='color:#298A08;'>".$all_thema_name[$pi_value['thema_no']]['thema_name']."</font>";
																															  else $thema_tags="<img src='../img/dot_uu.gif'> ";

																															  

																																$return_news['tag'][$rel_stock_info[1]]=" &nbsp; &nbsp; ".$thema_tags." <a onclick=\"window.open('".$pi_value['news_link']."','news','width=900, height=1900');\" style='font-size:15px;cursor:hand;text-decoration:dashed underline blue;text-underline-offset: 5px;'>".$news_tit."</a>";

																																$return_news['no'][$rel_stock_info[1]]=$pi_value['no'];
																														  }

																	 } # end of day


																 elseif($type=="stock") {

																					   if($pi_value['thema_no']>0) $thema_tags="<img src='../img/ico_thema.gif'> <font style='color:#298A08;'>".$all_thema_name[$pi_value['thema_no']]['thema_name']."</font>";
																															  else $thema_tags="<img src='../img/dot_uu.gif'> ";

																						$return_news['tag'][$pi_value['news_day']]=" &nbsp; &nbsp; ".$thema_tags." <a onclick=\"window.open('".$pi_value['news_link']."','news','width=900, height=1900');\" style='font-size:15px;cursor:hand;text-decoration:dashed underline blue;text-underline-offset: 5px;'>".$pi_value['news_title']."</a>";

																	 } # end of stock

								} # end of foreach
} # end of chk value

return $return_news;




#################################################################
} # end of top_price_get_stock_news_info
#################################################################

#################################################################
function top_pi_get_thema_all_pdo($qry, $pdo) {  # 테마 가져오기 (초간결 버전)
#################################################################
    if (empty($qry)) return [];
    $return_thema = [];

    // 1. 메인 종목 리스트 초고속 조회
    $result_stock = $pdo->query($qry)->fetchAll(PDO::FETCH_ASSOC);

    if (empty($result_stock)) return [];

    // 2. 루프 바깥에 테마 조회 쿼리 딱 1번만 장전
    $stmt_thema = $pdo->prepare("SELECT thema_no FROM tbl_daily_thema_stock WHERE stock_code = ? AND thema_no > 0 GROUP BY thema_no ORDER BY no DESC LIMIT 4");

    // 3. 종목별로 테마 번호 쏙쏙 매칭
    foreach ($result_stock as $r) {
        $code = $r['stock_code'];
        $stmt_thema->execute([$code]);
        
        // 🚀 마법의 FETCH_COLUMN: 2차원 배열이 아니라 딱 [1, 5, 12] 같은 깔끔한 숫자 배열로 한 방에 가져옵니다!
        $thema_ids = $stmt_thema->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($thema_ids)) {
            $return_thema[$code] = $thema_ids;
        }
    }

    return $return_thema;
}

#################################################################
function top_pi_get_thema_all($qry,$connect) {  #  테마 가져오기 
#################################################################

    $result_stock=mysqli_query($connect, $qry); 
   
foreach($result_stock as $r_no => $r_value){

                    $stock_code=$r_value['stock_code'];
                    $query_thema="SELECT thema_no,rel_news_no FROM `tbl_daily_thema_stock` WHERE stock_code='".$stock_code."' and thema_no>0 group by thema_no  order by no desc  limit 0,4";
		  	        $result_thema=mysqli_query($connect,$query_thema); 

						foreach($result_thema as $t_no => $t_value){
                        			           	   
												   $return_thema[$stock_code][]=$t_value['thema_no'];

		                              }
}
				
						

return $return_thema;

#################################################################
} # end of top_price_get_stock_news_info
#################################################################


#################################################################
function top_price_get_stock_info($s_value,$GR_Vals,$type,$connect) {  #  주식, 돌파
#################################################################

	$dp_on=1;

	$dft_vals="mode=".$GR_Vals['mode']."&pop=".$GR_Vals['pop']."";

if($type=='update') {
																		 if($GR_Vals['max_times']==1) $qry_times="stock_5m50_times=stock_5m50_times+1";										
																		 elseif($GR_Vals['max_times']==11) $qry_times="stock_5m50_times=stock_5m50_times-1";										


																		 if($GR_Vals['max_times']==8) $qry_times="stock_hRate_times=stock_hRate_times+1";										
																		 elseif($GR_Vals['max_times']==81) $qry_times="stock_hRate_times=stock_hRate_times-1";										


																		 else if($GR_Vals['max_times']==2) $qry_times="stock_box_times_5m=stock_box_times_5m+1";										
																		  else if($GR_Vals['max_times']==21) $qry_times="stock_box_times_5m=stock_box_times_5m-1";	


																		#  else if($GR_Vals['max_times']==22) $qry_times="stock_box_times_day=stock_box_times_day+1";										
																		#  else if($GR_Vals['max_times']==23) $qry_times="stock_box_times_day=0";	
																		 
																		 else if($GR_Vals['max_times']==3) $qry_times="stock_cross_up=1 , cross_up_type=1";	
																		 else if($GR_Vals['max_times']==31) $qry_times="stock_cross_up=-1";										
																		 else if($GR_Vals['max_times']==32) $qry_times="stock_cross_up=-2";
																		else if($GR_Vals['max_times']==33) $qry_times="cross_up_type=2";

																		   # 
																			 else if($GR_Vals['max_times']==4) $qry_times="today_top_pick=1";										
																			 else if($GR_Vals['max_times']==41) $qry_times="today_top_pick=0";
																		

																			 # 돌파
																			 else if($GR_Vals['max_times']==5) $qry_times="stock_high_down=stock_high_down+1";										
																			else if($GR_Vals['max_times']==51) $qry_times="stock_high_down=0";										

																			 # 신고가
																			 else if($GR_Vals['max_times']==7) $qry_times="stock_new_record=stock_new_record+1";										
																			else if($GR_Vals['max_times']==71) $qry_times="stock_new_record=0";										
																			
																					$qry_stock_vol_max="update  `tbl_daily_stock_vol`   set ".$qry_times." where no='".$GR_Vals['no']."'  ";
																					mysqli_query($connect,$qry_stock_vol_max); 

																				
																					$dp_on=0;

}



elseif($type=='insert') {

			 $dft_opt_tag="&opt=".$GR_Vals['opt']."";

		     $max_times_array=array(5,51,1,11,2,21,3,31,32,33,7,71,8,81);

			 for($r=0;$r<count($max_times_array);$r++) {

				 $mx=$max_times_array[$r];

				 $max_times[$mx]="<a onclick=\"location.href='$cur_php?$dft_vals&max_times=".$mx."&no=".$s_value['no']."&uDate=".$GR_Vals['uDate'].$dft_opt_tag."'\"  style='cursor:hand;'>";
			}
		
}

elseif($type=='display') {

	$max_times="";


}



if($dp_on) {  # display

            			   if(!($GR_Vals['chk_pass_day']))	 $grp_write_tag=       "<a onclick=\"open_popUp('".$s_value['today_tr_no']."','".$s_value['no']."',6);\" style='cursor:hand;'><img src='../img/c7.gif' title='그래프 등록'></a>"; 
																											  
																												      $stock_stactic_tags="<table style='font-size:13px;' border=0  cellspacing=0><tr>";

																													  if($type=='display') $stock_stactic_tags.="";
																													  else $stock_stactic_tags.="<td width=15>".$grp_write_tag."</td>";

																													   $stock_stactic_tags.="<td width=230><img src='../img/icon_best.gif' title='Price Box:가격박스'> 최고거래량 ".$max_times[2]."(分)</a> ";
																														 if($s_value['stock_box_times_5m']) $stock_stactic_tags.=$max_times[21]."<font style='font-size:17px;color:black;font-weight:bold;text-decoration:underline;'>".$s_value['stock_box_times_5m']."</font>  ";

																														 ## 가격박스(일목균형표) 주간
																														 ## 주간으로 가격박스가 있다면 추세가 확실히 변한다는 것을 의미함.

																															  $stock_box_times_day_array=array(0,"","<img src='../img/n2.gif' style='color:red;'><font style='color:white;background-color:red;'>첫</font>");

																															# if(!$s_value['stock_box_times_day']) $mt_week_vals=$max_times[22]; else $mt_week_vals=$max_times[23]; 

																															 
																														  $stock_stactic_tags.=$mt_week_vals."</a>";

																														  if($s_value['stock_box_times_day']) $high_price="(".deco_txt($s_value['stock_price_high'],3,0).")";

																														 if($s_value['stock_box_times_day']) $stock_stactic_tags.=" (日)<font style='font-size:14px;color:black;font-weight:bold;'>".$stock_box_times_day_array[$s_value['stock_box_times_day']]."$high_price</font>  ";
																														 else $stock_stactic_tags.=" ";


																														 #첫봉돌파

																														 $stock_stactic_tags.="<td width=130px>".$max_times[3]."<img src='../img/sweety/16-heart-red-m.png' >H5+첫봉</a>";
																														 
																														if($s_value['stock_cross_up']>0) $stock_stactic_tags.="<font style='font-size:20px;color:black;font-weight:bold;text-decoration:underline;'>".$max_times[31].$s_value['stock_cross_up']."</a></font>";
																														elseif($s_value['stock_cross_up']<0) $stock_stactic_tags.=$max_times[32]." <fotn style='font-size:20px;color:blue;font-weight:bold;text-decoration:underline;'>".$s_value['stock_cross_up']."</a></font>";

																														if($s_value['cross_up_type']==1) $stock_stactic_tags.=$max_times[33]."(H→F)</a>"; 
																														if($s_value['cross_up_type']==2) $stock_stactic_tags.="(F→H)"; 

																													
																														
																													#  if($s_value['stock_high_down']==1) $stock_stactic_tags.="<td width=90>".$max_times[5]."<font style='color:blue;font-weight:bold;'>*고가하락(日)</font></a> ";
																													#  elseif($s_value['stock_high_down']==2) $stock_stactic_tags.="<td width=90>".$max_times[5]."<font style='color:red;font-weight:bold;'>*고가돌파(日)</font></a> ";
																													 #  elseif($s_value['stock_high_down']>=3) $stock_stactic_tags.="<td width=90>".$max_times[51]."<font style='color:black;font-weight:bold;'>*고가터치(日)</font></a> ";
																													 # else   $stock_stactic_tags.="<td width=100 style='color:#A4A4A4;'>".$max_times[5]."<img src='../img/bul_dn_blue2.gif'>고가하락(日)</a>";


																														#$support_line_type_array=array(0,"H5","첫봉H","박스","평단가");

																														 #if(!$s_value['support_line_type']) $support_line_type_vals=$max_times[4]; else $support_line_type_vals=$max_times[41]; 

																														# $stock_stactic_tags.="</td><td>".$support_line_type_vals."&nbsp; <font style='color:gray;' title='첫봉,H5'>(지지)</a> ";
																														# if($s_value['support_line_type']>0) $stock_stactic_tags.="<font style='font-size:14px;color:black;font-weight:bold;text-decoration:underline;'>".$max_times[4].$support_line_type_array[$s_value['support_line_type']]."</a></font>";

																														 	$stock_new_record_array=array(0,"日","日週");

																														 if($s_value['stock_new_record']!=2) $stock_new_record_vals=$max_times[7]; else $stock_new_record_vals=$max_times[71]; 

																														 $stock_stactic_tags.="</td><td width=180>".$stock_new_record_vals."&nbsp; <font style='color:gray;' title='200일,156주(3년)'>(新고가)</a> ";
																														 if($s_value['stock_new_record']>0) $stock_stactic_tags.="<font style='font-size:14px;color:red;font-weight:bold;'>".$stock_new_record_vals.$stock_new_record_array[$s_value['stock_new_record']]."</a>(".deco_txt($s_value['stock_price_high'],3,0).")</font></td>";


																														  $stock_stactic_tags.="</tr></table>";
    
   return $stock_stactic_tags;

} # display






#################################################################
} # end of top_price_get_stock_info
#################################################################




################### start of prj_vieW #######################
 function  top_price_grp($connect,$pdo) {   # ★★★★★★★★ 매매기록
################### start of prj_vieW #######################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;

$cur_hour=date("H");
if($cur_hour<16) $tr_on=1; # 장중이면 입력창이 작게 표시됨

						   if($tr_on) { #장중
										     $cts_wdt="530px";
											 $cts_hgt="600px";

											 $cmt_wdt="540px";
											 $cmt_hgt="300px";

											 $chk_tr_on="<td><input type=checkbox name='grp_type' value=1 checked>* 장중</td>";

											 $dft_cmt="<1분><br><br>";



									   }

						  else {
										   	$cts_wdt="1160px";
											 $cts_hgt="1100px";

											 $cmt_wdt="550px";
											 $cmt_hgt="200px";

											 											 $dft_cmt="<5분><br><br><1분><br><br>";
									   }


$GR_Vals=Get_Vals('mode');

# 컨텐츠에서 이미지 추출하기
$max_width=$tbl_width['i5t']*0.97;



if($GR_Vals['type']=='update') {


#체크 리스트 
 foreach($GR_Vals['chk_list'] as $cl_key => $cl_value)	     $cl_tags.=$cl_value."-";
 $cl_tags=",chk_list='".substr($cl_tags,0,-1)."'";

                                                            	 if(($GR_Vals['today_top_pick'])) $today_top_pick_qry=",today_top_pick=1";
                                                             	 if(($GR_Vals['cmt'])) $cmt_qry=",cmt='".$GR_Vals['cmt']."'";

															 $qry_stock_up="update  `tbl_daily_stock_vol`   set grp_cnt=grp_cnt+1 ".$today_top_pick_qry." where no='".$GR_Vals['no']."'  ";

															 if(empty($GR_Vals['grp_cnt']))  $qry_grp_up="insert into  `tbl_daily_stock_vol_grp`   set  contents='".$GR_Vals['contents']."',tr_yes='".$GR_Vals['tr_yes']."',grp_type='".$GR_Vals['grp_type']."' , stock_vol_no='".$GR_Vals['no']."'".$cl_tags.$cmt_qry;  
															 else  $qry_grp_up="update  `tbl_daily_stock_vol_grp`   set  contents='".$GR_Vals['contents']."'".$cl_tags.$cmt_qry." where stock_vol_no='".$GR_Vals['no']."'  ";  

															if($test_on) { echo $qry_stock_up;
																					echo $qry_grp_up;
																					}
																					
															else {  mysqli_query($connect,$qry_stock_up);  
																		mysqli_query($connect,$qry_grp_up);  

															
									echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=top_pi_list&opt=".$GR_Vals['opt']."&no=".$GR_Vals['no']."&uDate=".$GR_Vals['uDate']."\";
													 self.close();
												</script>	 
										";		   

									}

exit;


}



   
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

        <BODY leftmargin=0 topmargin=5 marginwidth=\"0\" marginheight=\"0\" align=center>";

##if($GR_Vals['opt']=='today_tr') { 	


		$order_str=" order by";
		$today_tr_qry="and ( today_tr_no>0 or grp_cnt>0 ) ";  
		$order_str.=" today_tr_cap desc, stock_rate desc, stock_vol_1m_cap desc ";
  
$get_stock_info=all_stock_info($pdo);

$query_stock['qry']="SELECT no,stock_code,today_tr_cap,today_tr_no,uDate from `tbl_daily_stock_vol`  where uDate='".$GR_Vals['uDate']."' ".$today_tr_qry.$order_str."";
$result_list=php_mysql_query($query_stock,$connect); 

if($result_list['value']) {

				   $r_list= "<table style='font-size:14px;'><tr>";

					foreach($result_list['value'] as $l_no => $l_value){

						$j=$l_no+1;

						#$stock_std_name=shorten_Str($stock_std_info['stock_name'],6,'');

						$quick_no[$l_no]=$l_value['no'];
						$quick_name[$l_no]=$get_stock_info[$l_value['stock_code']]['stock_name'];

						$today_tr_no[$l_value['no']]=$l_value['today_tr_no'];

						if($l_value['today_tr_no']) $today_tr_tag="*";
						else $today_tr_tag="";

						$r_list.="<td>";
						
						if($GR_Vals['no']!=$l_value['no']) $r_list.="<a href=\"$cur_php?mode=top_pi_grp&type=".$GR_Vals['type']."&no=".$l_value['no']."&opt=".$GR_Vals['opt']."&uDate=".$GR_Vals['uDate']."\">";
					
						else { 
							       $r_list.="<font style='color:red;font-weight:bold;'>"; 
									$vals=array('type'=>'trade_multi_view','stock_code'=>$l_value['stock_code'],'uDate'=>$l_value['uDate'],'detail'=>1);
									$get_stock_tr=get_daily_tr_info($vals,$connect);
						}

							$r_list.=$today_tr_tag.shorten_Str($quick_name[$l_no],6,'')."</font> &nbsp;</td>";

						 $mode_no=$l_no%12;
																																																																												  
						  if($mode_no==0 and $l_no>1) $r_list.= "</tr><Tr>";

					}

					$r_list.= "</tr></table>";



   $find_no=	  array_search($GR_Vals['no'],$quick_no);
  						


     if( $find_no!=count($quick_no)-1) { 
		$nxt_tag="<input type=button value=' ".$quick_name[$find_no+1]." ▶▶▶' onclick=location.href=\"$cur_php?mode=top_pi_grp&type=".$GR_Vals['type']."&no=".$quick_no[$find_no+1]."&opt=".$GR_Vals['opt']."&uDate=".$GR_Vals['uDate']."\" style='width:330px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:77px;font-size:25px;'>"; 	
		}

$cur_tag="<input type=button value=' ".$quick_name[$find_no]." ' style='width:350px;background-color:black;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:77px;font-size:35px;color:white'>"; 	

    if( $find_no!=0) { 
		$prv_tag="<input type=button value='◀◀◀ ".$quick_name[$find_no-1]."' onclick=location.href=\"$cur_php?mode=top_pi_grp&type=".$GR_Vals['type']."&no=".$quick_no[$find_no-1]."&opt=".$GR_Vals['opt']."&uDate=".$GR_Vals['uDate']."\" style='width:330px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:77px;font-size:25px;'>"; 	
		}



} # end of $result_list['value']

 $today_tr_list="<Tr height=90px;><td colspan=3 align=center>$prv_tag  &nbsp; $cur_tag &nbsp; $nxt_tag </td></tr> ";

#}  # end of today_tr_list;



if($GR_Vals['type']=='view') {

	$arr_grp['qry']="SELECT * FROM `tbl_daily_stock_vol_grp`  where stock_vol_no='".$GR_Vals['no']."' ";  # limit 0,30
	$result_grp=php_mysql_Query($arr_grp,$connect);

   	echo "        <table width='100%' align=\"center\" border=0 cellspacing=\"0\" cellpadding=\"0\"  align=center ><tr><td> ";  ## start of table 000 

	  echo "<table   style='border: 1px dashed orange; border-radius: 7px; border-spacing:3px;font-size:15px;' width=98% align=center border=0>"; ## start of table 000 -001

	  echo "<Tr><td colspan=3>".$r_list."</td></tr>";


	   echo $today_tr_list;

      echo "<Tr><td colspan=2 style='height:60px;font-size:25px;border-spacing:3px;text-align:center;'> ".$get_stock_tr['tag']."</td><td width=30px;></td></tr>";
	  

if($result_grp['value']) {
					foreach($result_grp['value'] as $g_no => $g_value){		

								    $cat=array('cat_type'=>'tr_stg','disp'=>'view','cat_value'=>$g_value['chk_list']);
		                            if($today_tr_no[$g_value['stock_vol_no']])$get_chkbox_tag = get_chkbox_category($cat,$connect);

									
					if($g_value['grp_type']) {


								  echo "<Tr><td colspan=2>
									
										        <table width=100% border=0 style='border: 1px dashed orange; border-radius: 7px; border-spacing:3px;font-size:15px;' >
												<tr>
												    <td width=500px;>".$g_value['contents']."</td>

													 <td valign=top>

													           <table  style='font-weight:bold;height:60px;font-size:25px;border-spacing:13px;border: 1px dashed gray; border-radius: 17px; border-spacing:1px;background-color:#F2F2F2;' width=600px;>
													                       <tr><Td style='font-weight:bold;height:60px;font-size:25px;border-spacing:3px;'><br>(".explode(' ',$g_value['uDate'])[1].")</td></tr>

																			<Tr height=200px;><td width=400px;>".$get_chkbox_tag."</td></tr>

																			<tr height=300px; valign=top><Td style='font-weight:bold;height:60px;font-size:25px;'>".nl2br($g_value['cmt'])."</td></tr>
															   </table>


													</td>
												 </tr>
												</table>
											</td></tr> ";



					}

					else {

								  echo "<Tr><td colspan=2>
										  <table width=100% border=0>
												<tr>
													<td width=400px;>".$get_chkbox_tag."</td>
													<Td style='font-weight:bold;height:60px;font-size:25px;border-spacing:3px;'>
														  (".explode(' ',$g_value['uDate'])[1].") ".nl2br($g_value['cmt'])."
													</td>
												 </tr>
												</table>
											</td>

										  <td width=30px;></td></tr>";
										   echo "<Tr><td colspan=3>".$g_value['contents']."</td></tr>";

					}


					}

	}

echo "<Tr><td colspan=3 align=center>
<input type=button value='창 닫기' onclick=\"javascript:self.close();\" style='width:150px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'> </td></tr>";

echo "</table></td></tr></table>";	





} # end of view_all


elseif($GR_Vals['type']=='write') {


   if($tr_on) echo "장중입니다.";

            #$cat=array('cat_type'=>'tr_stg','disp'=>'view','cat_value'=>$tra_value['chk_list']);

		    $cat=array('cat_type'=>'tr_stg','cat_name'=>'chk_list','disp'=>'select');


											 

		    if($GR_Vals['today_tr_no']) { $get_chkbox_tag = get_chkbox_category($cat,$connect); 
			
			
			                                 $tr_yes_tag="<table><tr><td><img src='../img/3d.gif'> 거래 내용 평가</td></tr>
											 <tr><td><input type='radio'  name='tr_yes'  value='2'>	(+2) 최상의 매매인가요?</td></tr>
											 <tr><td><input type='radio'  name='tr_yes'  value='1'>	(+1) 잘한 매매인가요?</td></tr>
											 <tr><td><input type='radio'  name='tr_yes'  value='0' checked>	(0) 그냥 저냥?</td></tr>
											 <tr><td><input type='radio'  name='tr_yes'  value='-1'>	(-1) 잘못한 매매인가요?</td></tr>
											 <tr><td><input type='radio'  name='tr_yes'  value='-2'>	(-2) 최악의 매매인가요?</td></tr>											 
											 </table>";

											 $chk_tags="<input type=checkbox name=today_top_pick value=1>매매 영상 체크(Today Top Pick)";
			
			  }
                                  




										echo "
																  <script type=\"text/javascript\">
																													function     submit_Confirm(v) {		

																														

																																																  oEditors.getById['ir1'].exec('UPDATE_CONTENTS_FIELD',[]);
																																																  contents= document.getElementById('ir1').value;


																																																		if(contents=='<p>&nbsp;</p>') { 
																																																				 alert('내용없음'); 
																																																				   return;
																																																		}  

																																																  if(v.today_tr.checked ==true)	v.today_tr.value=1;

																																															
																																																  v.submit();

																																											} // end of submit_Confirm
																			</script>";




										echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:15px;' width=98% align=center border=0> "; # start of 1번째  tbl

										echo "<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
																																																					<input type='hidden' name=mode value='top_pi_grp'>
																																																					<input type='hidden' name=type value='update'>	
																																																					<input type='hidden' name=no value='".$GR_Vals['no']."'>
																																																					<input type='hidden' name=uDate value='".$GR_Vals['uDate']."'>	
																																																					<input type='hidden' name=grp_cnt value='".$GR_Vals['grp_cnt']."'>	
																																																					<input type='hidden' name=all value='".$GR_Vals['all']."'>
																																																					<input type='hidden' name=opt value='".$GR_Vals['opt']."'>	

												 ";


														# 시작 :스마트 에디터 불러오기



									echo "<script type=\"text/javascript\" src=\"../smart_editor/js/HuskyEZCreator.js\" charset=\"utf-8\"></script>";


											 $contents_tags="<textarea name=contents id=\"ir1\" style=\"width:".$cts_wdt."; height:".$cts_hgt.";display:none;\">".$dft_cmt."</textarea>";
											 $cmt_tags="<textarea name='cmt' style=\"width:".$cmt_wdt."; height:".$cmt_hgt.";font-size:20pt;border-radius: 7px;font-weight:bold;border:dashed 1px gray; overflow-x:hidden; overflow-y:auto;font-size:14pt; padding-top:5px; padding-right:5px; padding-bottom:5px; padding-left:5px;border:dashed 1px orange;\" class=form_nc $auto_clear_tag >코멘트</textarea>";

											 

									   if($tr_on) { #장중
										     
										   echo "<tr><td width='$cts_wdt'>".$contents_tags."</td>
														<td width='$cmt_wdt'  align=left>	
																<table border=0>
																        <tr>". $chk_tr_on."</tr>
																		<tr><td>".$get_chkbox_tag."</td></tr>
																		<tr><td>".$tr_yes_tag."</td></tr>
																			<Tr><td>".$cmt_tags."</td></tr>
																			<Tr><td>".$chk_tags."</td></tr>
																</table>														
														</td>
														
														</tr>";
									   }

									  else { #장마감
										  										     
										   echo "<tr align=center valign=top>										   			
																<td>".$get_chkbox_tag."</td>
																<td>".$tr_yes_tag."</td>
																<td>".$cmt_tags."</td>
														</tr>
														<Tr><td>".$chk_tags."</td></tr>
										   
										   <tr align=center>
										   <td colspan=3>".$contents_tags."</td>														

														</tr>";
									  

									  }


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


	                                	echo "<tr><td align=center colspan=3> <input type=button value='등 록' onclick=\"javascript:submit_Confirm(document.myform);\" style='width:150px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'>  <input type='hidden' name=today_tr value='1'></td> </tr>";

																				
										   echo  "</table> "; # start of 1번째  tbl



}




#################################################################
} # end of stock_vol_float_update($connect)
#################################################################



#################################################################
function stock_vol_float_update($connect) {  # 유통가능수량 입력
#################################################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;

$today = date("Y-m-d");
$GR_Vals=Get_Vals('mode');


$stock_info=get_stock_info($GR_Vals['stock_code'],$connect);


if($test_on)print_r($result_stock['value']);

# 기존 유통주식수 찾기
$query_stock_vol="SELECT * from `all_stock_float`  where stock_code='".$GR_Vals['stock_code']."' ";
$result_stock_vol=mysqli_query($connect,$query_stock_vol); 

$get_stock_vol_float=mysqli_fetch_array($result_stock_vol);


echo "<html><body>";


if($GR_Vals['type']=='update') {

					if($get_stock_vol_float) { 								                       
																		   $qry_stock="update  `all_stock_float`   set  stock_vol_float='".$GR_Vals['stock_vol_float']."' where stock_code='".$GR_Vals['stock_code']."'  ";

											}

				   else  {  # 신규로 입력함

						  $qry_stock="insert into  `all_stock_float`   set  stock_vol_float='".$GR_Vals['stock_vol_float']."' , stock_code='".$GR_Vals['stock_code']."'  ";

				   }

						mysqli_query($connect,$qry_stock); 


  ##  유통주식수 정보 업데이트


 $qry_stock_up="update  `tbl_daily_stock_vol`   set  stock_vol_float='".$GR_Vals['stock_vol_float']."' where no='".$GR_Vals['no']."'  ";
	mysqli_query($connect,$qry_stock_up); 
 

#  echo "<body  onload='javascript:self.close();opener.location.reload();'>";

						echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=top_pi_list&no=".$GR_Vals['no']."\";
													 self.close();
												</script>	 
										";		   



exit;

}
   

echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:15px;' width=100%> "; # start of 1번째  tbl
echo "<tr><td style='background-color:yellow;font-size:20px;'>".$stock_info['stock_name']." </td></tr>";
echo "<tr><td>유통주식수 입력 </td></tr>";





echo "<tr><td><form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
																																											<input type='hidden' name=mode value='vol_float'>
																																											<input type='hidden' name=type value='update'>	
																																											<input type='hidden' name=no value='".$GR_Vals['no']."'>	
																																											<input type='hidden' name=stock_code value='".$GR_Vals['stock_code']."'>	

<input type='text' name='stock_vol_float'  id='stock_vol_floast'  size='7' value='".$get_stock_vol_float['stock_vol_float']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																											
<input type='submit' class='form_nc' value='등록'  style='width:45px;'>	


         </td></tr>";

										
   echo  "</table> "; # start of 1번째  tbl
																					


echo "</body></html>";





#################################################################
} # end of stock_vol_float_update($connect)
#################################################################





#################################################################
function stock_max_vol_update($connect) {  # 당일 순위분석  분봉 최대 거래량 업데이트
#################################################################

global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;


$GR_Vals=Get_Vals('mode');

$stock_info=get_stock_info($GR_Vals['stock_code'],$connect);

$query_stock['qry']="SELECT * from `tbl_daily_stock_vol`  where no='".$GR_Vals['no']."'";
$result_stock=php_mysql_query($query_stock,$connect); 

if($result_stock) $get_stock_vol_max=$result_stock['value'][0];


 $skip_Array=array('type','no');
 $make_qry= make_qry($GR_Vals,$skip_Array,"skip_yes");


if($get_stock_vol_max['stock_vol_tick_gc']) { 

   $tick_gc_checked="checked";

}


echo "<html><body>";

echo ("
     $style_css


											");


if($GR_Vals['type']=='update') {
			
									   if(empty($get_stock_vol_max['up_Times']))  $vol_1m_first_qry="stock_vol_1m_first='".$GR_Vals['stock_vol_1m_max']."',";

									    if(empty($get_stock_vol_max['stock_limit_vol']))  $stock_limit_vol_qry="stock_limit_vol='".$GR_Vals['stock_limit_vol']."',";

									    if(($GR_Vals['stock_vol_max_rate']>1))  $stock_box_times_day_qry="stock_box_times_day=1,";


									   $qry_stock_up="update  `tbl_daily_stock_vol`   set  ".$vol_1m_first_qry.$stock_limit_vol_qry.$stock_box_times_day_qry.$make_qry."  where no='".$GR_Vals['no']."'  ";
										 
									if($test_on) print_r($qry_stock_up); 
									else {
										mysqli_query($connect,$qry_stock_up); 
										
										#echo "<body  onload=\"self.close();opener.location.href='$cur_php?mode=top_pi_list'\">";

										#opener.location.href=$cur_php?mode=top_pi_list&no='".$GR_Vals['no']."';

					           if(!$test_on) 
										echo "
													<script>
													var tmpOpener = window.opener;  // opener정의
													 tmpOpener.location.href=\"$cur_php?mode=top_pi_list&opt=".$GR_Vals['opt']."&no=".$GR_Vals['no']."\";
													 self.close();
												</script>	 
										";		   

									}

exit;

}

if($GR_Vals['type']=='tick_num') {



  $qry_stock_up="update  `tbl_daily_stock_vol`   set ".$make_qry."   where no='".$GR_Vals['no']."'  ";
										 
									if($test_on) print_r($qry_stock_up);
									else {
										mysqli_query($connect,$qry_stock_up); 

											echo "
													<script>
													 location.href=\"$cur_php?mode=top_pi_list&opt=".$GR_Vals['opt']."&no=".$GR_Vals['no']."\";
												</script>	 
										";		 
										exit;

									}
	
										
				
									 




}





if($GR_Vals['type']=='del') { # 삭제하기
			
   $qry_stock_up="delete from `tbl_daily_stock_vol`  where no='".$GR_Vals['no']."'  ";
	mysqli_query($connect,$qry_stock_up); 
 
   #echo "<body  onload='javascript:self.close();opener.location.reload();'>";
   echo "<body  onload='javascript:self.close();opener.location.reload();'>";

exit;

}

if($GR_Vals['type']=='confirm') { # 삭제 확인창
		

echo "<table align=center>";

echo "<Tr><td>삭제하시겠습니까?</td></tr>";

echo "<tr align=center><td><a href='$cur_php?mode=vol_max&type=del&no=".$GR_Vals['no']."'>Yes</a>  |  <a href='javascript:self.close();'>No</a></td></tr>";

echo "</table>";
exit;

}



if($GR_Vals['today_tr']) { # 오늘 매매
			
   $qry_stock_up="update  `tbl_daily_stock_vol`   set today_tr=1  where no='".$GR_Vals['no']."'  ";
	mysqli_query($connect,$qry_stock_up); 
 
    echo "<body onload=location.href='$cur_php?mode=top_pi_list&opt=".$GR_Vals['opt']."&all=".$GR_Vals['all']."';>       ";

exit;

}
  
echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:3px;font-size:15px;' width=100% border=0> "; # start of 1번째  tbl
echo "<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>";
echo "<tr><td colspan=2 style='background-color:yellow;font-size:20px;'>".$stock_info['stock_name']."  &nbsp; &nbsp;<a href='$cur_php?mode=vol_max&type=confirm&no=".$get_stock_vol_max['no']."'><img src='../img/ic/12-em-cross.png'></a> </td></tr>";



echo "<tr><td>
																																											<input type='hidden' name=mode value='vol_max'>
																																											<input type='hidden' name=type value='update'>	
																																											<input type='hidden' name=no value='".$GR_Vals['no']."'>	
																																											<input type='hidden' name=stock_code value='".$GR_Vals['stock_code']."'>	
																																											<input type='hidden' name=opt value='".$GR_Vals['opt']."'>	



1분:: <input type='text' name='stock_vol_1m_max'  id='stock_vol_1m_max'  size='5' value='".$get_stock_vol_max['stock_vol_1m_max']."' maxlength='10'   style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>													
</td>
<td>
대금(억):: <input type='text' name='stock_vol_1m_cap'  id='stock_vol_1m_cap'  size='3' value='".$get_stock_vol_max['stock_vol_1m_cap']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>													
</td>
</tr>
<tr>
<Td>
X배:: <input type='text' name='stock_vol_max_rate'  id='stock_vol_max_rate'  size='2' value='".$get_stock_vol_max['stock_vol_max_rate']."' maxlength='5'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>
</td>
<Td >

<img src='../img/dot_r.gif'> 상한가잔량 :: <input type='text' name='stock_limit_vol'  id='stock_limit_vol'  size='4' value='".$get_stock_vol_max['stock_limit_vol']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>
</td>

</tr>




<tr>
<td>
Tick(총갯수):: <input type='text' name='stock_vol_tick_num'  id='stock_vol_tick_num'  size='3' value='".$get_stock_vol_max['stock_vol_tick_num']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																											
</td>

<td style='font-size:14px;text-align:left;' nowrap>
TickH(최대) :: <input type='text' name='stock_vol_tick_max'  id='stock_vol_tick_max'  size='3' value='".$get_stock_vol_max['stock_vol_tick_max']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																									
</td>
</tr>

<tr>
<td>
120T(시간):: <input type='text' name='stock_vol_tick_time'  id='stock_vol_tick_time'  size='3' value='".$get_stock_vol_max['stock_vol_tick_time']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																										
</td>

<td style='font-size:14px;text-align:left;' nowrap>
240T(시간):: <input type='text' name='stock_vol_tick_time2'  id='stock_vol_tick_time2'  size='3' value='".$get_stock_vol_max['stock_vol_tick_time2']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																											


</td>
</tr>

<tr><td colspan=2><input type=checkbox name='stock_vol_tick_gc' value=1 $tick_gc_checked>* Tick_골든크로스(120T<240T)</td></tr>

<tr>
<td style='font-size:14px;text-align:left;' nowrap>
120T(대금):: <input type='text' name='stock_vol_tick_cap'  id='stock_vol_tick_cap'  size='3' value='".$get_stock_vol_max['stock_vol_tick_cap']."' maxlength='10'  style='font-size:20px;border-radius: 7px;border:dashed 1px orange;' $auto_clear_tag>																																											
</td>

<td>

<input type=submit value='등록'  style='width:100px;cursor:hand;background-color:yellow;border-radius: 7px;font-weight:bold;border:dashed 1px gray;height:40px;font-size:25px;'> 
</td>

</tr>



<TR><TD colspan=2>

</TD></TR>


</table>

</form>



         </td></tr>";

										
   echo  "</table> "; # start of 1번째  tbl
																					


echo "</body></html>";





#################################################################
} # end of stock_max_vol_update($connect)
#################################################################

##############################################################
function file_upload_form() {
##############################################################
# 		
require "./env/e.fnc";
require "./env/inf.fnc";
global $cur_php;

  #변수할당
 $get_file=Get_Vals('mode');

 
#print_r($get_file);

  # 변수 할당

# <파일설명><INPUT type='text' name='fname'  id='fname' onfocus=\"select();\" style='width:200' class='form_nc'>
echo "
<html STYLE=\"width:100%; height: 130px; \">
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
require "./env/e.fnc";

  #변수할당

#print_r ($_POST);

  $get_file=Get_Vals('mode');

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





###########################################################
function get_scrap_news_tags($news_value,$key_word) { # 파일을 서버에
###########################################################
global $mobile;
global $admin_info;

if($admin_info['usr_level']==1) {
 $news_target="news_d4";		
 $disp_admin=1;

} else   $news_target="news"; 


$opt_deco['type']=21;
$opt_deco['str']="%";
$opt_deco['font']="11px;";

$up_day=calender_str(3,13,$news_value['uDate']);	

$rel_stock_tags="<table border=0 width=98%>";


$news_value['news_title']= str_replace($key_word,"<font style='color:red;font-weight:bold;font-size:20px;'>".strtoupper($key_word)."</font>",$news_value['news_title']);				               



$rel_stock_tags.="<tr height=40px;><td><font style='font-size:12px;'><a href='$cur_php?mode=dnsl&uDate=".explode(" ",$news_value['uDate'])[0]."' target='news_d2'>".$up_day['unix_str']."</font></a><br><a href='".$news_value['news_link']."' target='news_d5'>".$news_value['news_title']."</td></tr>";

$rel_stock_tags.="<tr><td>";


if($news_value['rel_stock_info']) {
																			 $rel_stock_array=explode('@@',$news_value['rel_stock_info']);

																																																																			  for($r=0;$r<count($rel_stock_array);$r++) {
																																																																				               
																																																																				                  $rel_stock_info=explode('#',$rel_stock_array[$r]);
																																																																								  $stock_name[$rel_stock_info[1]]=$rel_stock_info[0];

																																																																								  
																																																																								
																																																																								 if(!$mobile and $disp_admin) $infostock_open="<a onclick=\"window.open('".$cur_php."?mode=pop_url&pop_type=10010&stock_code=".$rel_stock_info[1]."','pop_hidden','width=10, height=10');\" style='cursor:hand;'>"; 
																																																																								 
																																																																								 else $infostock_open="<a href='https://new.infostock.co.kr/stockitem?code=".$rel_stock_info[1]."'>"; 

																																																																								  $rel_stock_info[0]= str_replace($key_word,"<font style='color:red;font-weight:bold;font-size:20px;'>".strtoupper($rel_stock_info[0])."</font>",$rel_stock_info[0]);				               
																																																																																																																																												  
																																																																				                 $rel_stock_array_tags.= $infostock_open.$rel_stock_info[0]."</a>".cur_deco_txt($opt_deco,$rel_stock_info[2],8,5,8)." (".cur_deco_txt($opt_deco,$rel_stock_info[3],8,5,8).")  ";

																																																																								  $mode_no=$r%2;																																																																								  
																																																																								  if($mode_no==1 ) $rel_stock_array_tags.= "<br>";																																																																							 

																																																																			             }

																																																																						  $rel_stock_array_tags="<table  style='border: 1px dashed orange; border-radius: 7px; background-color:white; border-spacing:3px;height:30px;padding:3px;' width=100%><tr><td style='line-height:27px;color:gray;font-size:12px;'>".substr($rel_stock_array_tags,0,-1)."</td></tr></table>";

} else $rel_stock_array_tags="";



$rel_stock_tags.=$rel_stock_array_tags."</td></tr>";


$rel_stock_tags.="</table>";

return $rel_stock_tags;




##############################################################
} # end of get_scrap_news_tags
##############################################################








#################################################################
function all_stock_attach ($connect) {
#################################################################
global $cur_php;


require "./env/inf.fnc";
require "./env/e.fnc";

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

					   
									  
									  <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> 전종목 주식 시세 업데이트 </td></tr>

									   <tr height=50>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
											  <input type='hidden' name=mode value='all_stock_up'>

											  <input type='text' name='up_day'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform.start_time','','0');\" style='cursor:hand'>

                                                <input type='radio'  name='opt'  value='0' checked>  가격,거래량,거래대금,전일비 
                                                <input type='radio'  name='opt'  value='stock_history'> 분기별 데이터( 1월,4월,7월,10월 )
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>


					               <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> 전종목 주식 재무정보 업데이트 ( EPS,PER, BPS,PBR, 배당수익률 ) </td></tr>
									<tr>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>	
											<input type='hidden' name=mode value='all_stock_up'>
											<input type='hidden' name=opt value='stock_finance'>
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>
									

							   <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> ETF 정보(기초자산,상장일,자산총액) </td></tr>
									<tr>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform2>	
											<input type='hidden' name=mode value='all_etf_up'>
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>


									  <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> ETF시세 </td></tr>

									   <tr height=50>
											<td>
											
											<form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform3>	
											  <input type='hidden' name=mode value='all_etf_price_up'>

											  <input type='text' name='up_day2'  id='start_time' value='$start_time' size='14' readonly class=form_nc onclick=\"check_mouse('myform3.start_time','','0');\" style='cursor:hand'>
												 <input type=\"file\" name=\"upfile\" maxlength=\"256\" class=form>												 
												   <input type=submit value=\"올리기\"  class=form2></form>
										</td>
																			
									</tr>


                                   <tr height=30 class=tt4><Td> &nbsp; <img src='../img/star_b.gif'> prj_analysis 시세 업데이트 </td></tr>
									<tr>
											<td>

											<a href='prj_yehior.php?mode=asl_pu'>실행</a> 
																		
																			
									</tr>

						</table>
					


					</body>
				</html>
				 ";


#################################################################
} # end of all_stock_attach ($connect) {
#################################################################


#################################################################
function all_stock_update ($connect,$pdo)  {  # 전종목 시세를 업데이트 함
#################################################################

    global $cur_php;
    require_once "./env/inf.fnc";
    require_once "./env/e.fnc";

    $test_on = 0; // 1이면 화면에 출력만 하고 DB엔 넣지 않음
    $GR_Vals = Get_Vals('mode');
    
    // PHP 7+ 안전 처리 (값이 없으면 빈 문자열)
    $opt = $GR_Vals['opt'] ?? '';
    $up_day_raw = $GR_Vals['up_day'] ?? date('Y-m-d');
    $up_day = explode(" ", $up_day_raw)[0];

    // ==========================================================
    // 1. 초기 데이터 삭제 (PHP 7 버그 완벽 방어: === 사용)
    // ==========================================================
    if ($opt === '0' || $opt === '') { 
        // TRUNCATE가 DELETE보다 수십 배 빠르고 깔끔합니다.
        $pdo->exec("TRUNCATE TABLE all_stock_info"); 
    }
    if ($opt === 'stock_history') {
        $stmt_del = $pdo->prepare("DELETE FROM all_stock_history_info WHERE uDate = :uDate");
        $stmt_del->execute(['uDate' => $up_day]);
    }

    // ==========================================================
    // 2. 🚀 파일 업로드 검증 및 스트림(fgets) 열기
    // ==========================================================
    if (!isset($_FILES['upfile']) || $_FILES['upfile']['error'] !== UPLOAD_ERR_OK) {
        echo "<script>alert('파일 업로드 오류!'); history.back();</script>";
        return;
    }
    $tmp_name = $_FILES['upfile']['tmp_name'];
    $file_open = fopen($tmp_name, "r");

    if ($file_open) {
        // ==========================================================
        // 3. 🚀 쿼리 '미리 준비' (속도 10배 향상의 핵심)
        // ==========================================================
        if ($opt === 'stock_finance') {
            $stmt_main = $pdo->prepare("UPDATE all_stock_info SET eps=?, pre_eps=?, per=?, pre_per=?, bps=?, pbr=?, div_rate=? WHERE stock_code=?");
        } elseif ($opt === 'stock_history') {
            $stmt_main = $pdo->prepare("INSERT INTO all_stock_history_info (stock_code, stock_name, stock_price, stock_vol, stock_cap, uDate) VALUES (?, ?, ?, ?, ?, ?)");
        } else {
            $stmt_main = $pdo->prepare("INSERT INTO all_stock_info (stock_code, stock_name, stock_price, stock_high_price, stock_yrate, stock_rate, stock_rate_rt, stock_vol, stock_vol_cap, stock_cap, stock_vol_tot) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        }

        // ==========================================================
        // 4. 한 줄씩 읽어서 초고속 삽입/수정 (메모리 최적화)
        // ==========================================================
        while (($line = fgets($file_open)) !== false) {
            $clean_line = str_replace("\"", "", trim(iconv("EUC-KR", "UTF-8//IGNORE", $line)));
            if (empty($clean_line)) continue;

            $val = explode(",", $clean_line);

            // 데이터 정제


			$stock_vol = (float)($val[10] ?? 0);
			$stock_vol_cap = (float)($val[11] ?? 0) / 100000000;
			$stock_cap = (float)($val[12] ?? 0) / 100000000;

            if ($test_on) { echo implode(" | ", $val) . "<br>"; continue; }

            // 준비된 쿼리에 배열로 값만 던지기 (해킹 방어, 초고속)
            if ($opt === 'stock_finance') {
                $stmt_main->execute([$val[5], $val[7], $val[6], $val[8], $val[9], $val[10], $val[12], $val[0]]);
            } elseif ($opt === 'stock_history') {
                $stmt_main->execute([$val[0], $val[1], $val[4], $stock_vol, $stock_cap, $up_day]);
            } else {
                $stmt_main->execute([$val[0], $val[1], $val[4], $val[8], $val[5], $val[6], $val[6], $stock_vol, $stock_vol_cap, $stock_cap, $val[13] ?? 0]);
            }
        }
        fclose($file_open);

        // 업데이트 시간 기록 설정
        $target_key = ($opt === 'stock_finance') ? 'all_stock_finance_info' : (($opt === 'stock_history') ? 'all_stock_history' : 'all_stock_info');
        data_upTime($target_key, 'update', $pdo);

        // ==========================================================
        // 5. 연쇄 업데이트: 매매 내역 & 전략 종목 (PDO 최적화 적용)
        // ==========================================================
        if (!$test_on) {
            $all_stock_vol = all_stock_info($pdo); // 기존 함수 그대로 활용

            // 5-1. tbl_daily_stock_vol 업데이트
				$stmt_select = $pdo->prepare("SELECT no, stock_code FROM tbl_daily_stock_vol WHERE uDate = :uDate ORDER BY stock_rate DESC");
				$stmt_select->execute(['uDate' => $up_day]);
				$result_stock = $stmt_select->fetchAll(PDO::FETCH_ASSOC); // 결과를 연관 배열로 싹 다 가져옴

				// 2. UPDATE 쿼리 미리 준비
				$stmt_vol = $pdo->prepare("UPDATE tbl_daily_stock_vol SET stock_price=?, stock_price_high=?, stock_vol=?, stock_vol_cap=?, stock_rate=? WHERE no=?");
				
				// 3. 결과가 있으면 반복문 실행 (['value'] 같은 불필요한 껍데기가 사라짐!)

				if (!empty($result_stock)) {

					foreach ($result_stock as $s) {
						
						$c = $s['stock_code'];

						if(isset($all_stock_vol[$c])) {
									$stmt_vol->execute([$all_stock_vol[$c]['stock_price'], $all_stock_vol[$c]['stock_high_price'], $all_stock_vol[$c]['stock_vol'],$all_stock_vol[$c]['stock_vol_cap'], $all_stock_vol[$c]['stock_rate'], $s['no']		]);
						}
					}
				}
     

            // 5-2. tbl_trade_review 업데이트
            
						// 1. 순정 PDO로 SELECT 쿼리 실행 (보안을 위해 prepare 사용)
								$stmt_tr_select = $pdo->prepare("SELECT no, stock_code FROM tbl_trade_review WHERE sell_Date = :sell_Date");
								$stmt_tr_select->execute(['sell_Date' => $up_day]);
								$stock_tr_array = $stmt_tr_select->fetchAll(PDO::FETCH_ASSOC);

					// 2. UPDATE 쿼리 미리 준비
								$stmt_tr = $pdo->prepare("UPDATE tbl_trade_review SET stock_cap=?, stock_vol=?, stock_vol_cap=?, stock_high_price=?, stock_rate=? WHERE no=?");
										
					// 3. 결과가 있으면 반복문 실행 (불필요한 ['value'] 껍데기 제거!)
								if (!empty($stock_tr_array)) {
									foreach ($stock_tr_array as $tr) {
										$c = $tr['stock_code'];
										if (isset($all_stock_vol[$c])) {
											$stmt_tr->execute([
												$all_stock_vol[$c]['stock_cap'], 
												$all_stock_vol[$c]['stock_vol'], 
												$all_stock_vol[$c]['stock_vol_cap'], 
												$all_stock_vol[$c]['stock_high_price'], 
												$all_stock_vol[$c]['stock_rate'], 
												$tr['no']
											]);
										}
									}
								}

            // 5-3. tbl_stock_analysis 업데이트 (전략 종목)

				// 1. 순정 PDO로 SELECT 실행 (파라미터가 없으므로 query()로 즉시 실행)
								$sql_an = "SELECT no, stock_code, uDate_1, uDate_2, uDate_3, uDate_4, uDate_5 FROM tbl_stock_analysis";
								$stock_analysis_array = $pdo->query($sql_an)->fetchAll(PDO::FETCH_ASSOC);

				// 2. 결과가 있으면 반복문 실행 (불필요한 ['value'] 껍데기 제거!)
								if (!empty($stock_analysis_array)) {
									foreach ($stock_analysis_array as $a) {
										$c = $a['stock_code'];
										
										// 시세 데이터가 없으면 건너뜀
										if (!isset($all_stock_vol[$c])) continue;
										
										$shp = $all_stock_vol[$c]['stock_high_price'];
										$col = "";
										
										// 날짜 매칭 확인
										if ($a['uDate_1'] == $up_day) $col = "stock_high_price_1";
										elseif ($a['uDate_2'] == $up_day) $col = "stock_high_price_2";
										elseif ($a['uDate_3'] == $up_day) $col = "stock_high_price_3";
										elseif ($a['uDate_4'] == $up_day) $col = "stock_high_price_4";
										elseif ($a['uDate_5'] == $up_day) $col = "stock_high_price_5";

				// 3. 동적 쿼리 조립
										$update_sql = "UPDATE tbl_stock_analysis SET stock_price=?, stock_vol=?, stock_vol_cap=?, stock_high_price=?, stock_rate=?";
										
										if ($col !== "") {
											$update_sql .= ", {$col}=?";
										}
										$update_sql .= " WHERE no=?";

										// 쿼리 형태가 경우에 따라 달라지므로(동적 쿼리), 여기서 prepare를 수행합니다.
										$stmt_an = $pdo->prepare($update_sql);
										
				// 4. 파라미터 매칭 및 실행
										if ($col !== "") {
											$stmt_an->execute([
												$all_stock_vol[$c]['stock_price'], 
												$all_stock_vol[$c]['stock_vol'], 
												$all_stock_vol[$c]['stock_vol_cap'], 
												$shp, 
												$all_stock_vol[$c]['stock_rate'], 
												$shp, 
												$a['no']
											]);
										} else {
											$stmt_an->execute([
												$all_stock_vol[$c]['stock_price'], 
												$all_stock_vol[$c]['stock_vol'], 
												$all_stock_vol[$c]['stock_vol_cap'], 
												$shp, 
												$all_stock_vol[$c]['stock_rate'], 
												$a['no']
											]);
										}
									}
								}
           
	        } // end of 연쇄 업데이트
    
		// 6. 마무리 업데이트 설정



        echo "<meta http-equiv=\"refresh\" content=\"1;url=prj_yehior.php?mode=asl_pu\">";

    } else {
        echo "<script>alert('파일을 열 수 없습니다.'); history.back();</script>";
    }

#################################################################
} # all_stock_update ($pdo)
#################################################################




#################################################################
function etf_holdings_list($pdo) {
#################################################################

global $cur_php;
global $admin_info;
require "./env/inf.fnc";
require "./env/e.fnc";

	$GR_Vals=Get_Vals('mode');




#################################################################
} # etf_holdings_list
#################################################################




#################################################################
function etf_list($connect,$pdo) {
#################################################################
global $cur_php;
global $admin_info;
require "./env/inf.fnc";
require "./env/e.fnc";

	$GR_Vals=Get_Vals('mode');

####  과거에 등록한 데이터와 현재 가격을 비교, 기간 등락률


#print_r($admin_info);

# 특정일과 비교하기
#if($GR_Vals['history_uDate_c']=='on') 	   { setcookie('opt[history_uDate]',$GR_Vals['history_uDate'],time()+12800,'/');  $admin_info['history_uDate']=$GR_Vals['history_uDate']; }
#elseif($GR_Vals['history_uDate_c']=='off')  { setcookie('opt[history_uDate]',$GR_Vals['history_uDate'],time()-3600,'/');  $admin_info['history_uDate']=0; }




#print_r($GR_Vals);

#

     $target_key='all_etf_price';										   
	 $last_etf_update=data_upTime($target_key, 'call',$pdo);

     $GR_Vals['uDate'] = date("Y-m-d", strtotime($last_etf_update));

   $chk_img="check_on.gif";	   
   $chk_on="off";


   if($GR_Vals['order']=='desc') {
	   $order_qry="etf_rate desc,";
	   $order_tags="asc";

	   }
  elseif($GR_Vals['order']=='asc') {
	       $order_qry="etf_rate asc, ";
           $order_tags="";
	   }
  else { 
            $order_tags="desc";

  }


  $order_etf_tags="<a href='$cur_php".make_url(['order'=>$order_tags])."'>";

#

					 $qry_etf_price=" SELECT DISTINCT uDate FROM all_etf_price 
												WHERE is_locked = 1 
												ORDER BY uDate DESC 
												LIMIT 9 ";

				#	$qry_etf_price="SELECT uDate FROM `all_etf_price` where is_locked=1 group by uDate desc  limit 0,9 ";										

					$result_etf_price=mysqli_query($connect,$qry_etf_price); 

				   $tag_price="<table border=1><Tr><td><변동율비교></td><td><img src='../img/".$chk_img."'>최근($last_etf_update)</td>";

		foreach( $result_etf_price as $etf_price_key => $etf_price_value) {

						if($etf_price_value['uDate']==$GR_Vals['uDate']) $hu_tag="<img src='../img/go_on2.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=etf_list'\" style='cursor:hand;'><font color=red style='font-weight:bold;'> ";
						else  $hu_tag="<img src='../img/check_off.gif' style='cursor:hand;' onclick=\"location.href='$cur_php?mode=etf_list&uDate=".$etf_price_value['uDate']." ' \" style='cursor:hand;'> ";

					   $tag_price.= "<td style='font-size:14px;text-align:right;'>".$hu_tag."[".$etf_price_value['uDate']."]</td><td width=10px;></td>";

					}
				   $tag_price.="</tr></table>";

				echo "<tr><td style='font-size:13px;text-align:right;'>".$tag_price."</td><td><a href='$cur_php?mode=etf_holdings'>a</a></tD></tr>";



echo "<html><body>";

										echo $style_css;

echo ("
							   <script type=\"text/javascript\">
								</script>
				");


   	 #    [0] => 종목코드     [1] => 종목명     [2] => 상장일     [3] => 분류체계    [5] => 수익률(최근 1년)    [6] => 기초지수    [8] => 순자산총액    [10] => 변동성

 if($GR_Vals['uDate']) {	

					// 1. 테이블 이름에 별칭(i, p)을 붙여 쿼리를 압도적으로 짧고 읽기 쉽게 만듭니다.
						// 주의: ORDER BY의 컬럼명($order_qry)은 PDO 바인딩이 불가능하므로 기존처럼 변수를 직접 넣습니다.
						$sql = "SELECT 
									i.etf_code, 
									i.etf_name, 
									i.Listing_Date, 
									i.return_rate,
									i.underlying_index, 
									i.holdings_count,
									p.trading_value,
									p.etf_rate,
									p.market_cap,
									p.turnover_ratio 
								FROM all_etf_info AS i
								INNER JOIN all_etf_price AS p ON i.etf_code = p.etf_code
								WHERE p.uDate = :uDate 
								ORDER BY {$order_qry} p.trading_value DESC, p.turnover_ratio DESC";

						// 2. 쿼리 준비 및 안전한 값 삽입
						$stmt = $pdo->prepare($sql);
						$stmt->execute(['uDate' => $GR_Vals['uDate']]);

						// 3. 결과 가져오기 (기존의 ['value'] 껍데기 없이 깔끔한 배열로 바로 나옵니다)
						$result_etf = $stmt->fetchAll(PDO::FETCH_ASSOC);

						$etf_tit_tag="
						<tr height=45px; style='background-color:yellow;text-align:center'>
							<td>no</td>
							<Td width=290px;>ETF이름</td>
							<td>수익률(최근1년)</td>
							<td>".$order_etf_tags."등락률</a><br>(".$GR_Vals['uDate'].")</td>
							<td>회전율</td>
							<td>거래대금(억원)</td>

							<td>상장일</td>
							<td>기초지수</td>
							<td>등록된종목수</td>
						</tr>";

				 }

				 else {

					   # 등록하면 사라지도록 

										// 1. 기본 쿼리 시작과 빈 파라미터 배열 준비
										$sql = "SELECT * FROM all_etf_info WHERE (holdings_count = 0";
										$params = []; // 바인딩할 값들을 담을 상자

										// 2. 동적 조건 추가 (해킹 완벽 차단)
										if (!empty($GR_Vals['etf_code'])) {
											$sql .= " OR etf_code = :etf_code";        // 쿼리에는 이름표만 달아주고
											$params['etf_code'] = $GR_Vals['etf_code']; // 실제 값은 파라미터 배열에 안전하게 보관합니다.
										}

										// 3. 괄호 닫기 및 정렬 조건 마무리
										$sql .= ") ORDER BY total_nav DESC";

										// 4. 순정 PDO 실행
										$stmt = $pdo->prepare($sql);
										$stmt->execute($params); // 모아둔 파라미터 배열을 한 번에 던집니다!
										$result_etf = $stmt->fetchAll(PDO::FETCH_ASSOC);


						$etf_tit_tag="
							<tr height=45px; style='background-color:yellow;text-align:center'>
							<td>no</td>
							<Td width=290px;>ETF이름</td>
							<td>순자산(억)</td>
							<td>수익률(최근1년)</td>
							<td>상장일</td>
							<td>분류</td>
							<td>기초지수</td><td>변동성</td><td>등록된종목수</td></tr>";

				 }


echo "<table border=1>";

echo "<tr><td>"; // 좌측

							   echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:white; border-spacing:5px;' > "; # start of 1번째  tbl

							   echo $etf_tit_tag;
							   echo "<tr>".$dot_line."</tr>";
																												$tt = 0; // 순번 초기화

																	// 1. 🚀 불필요한 ['value'] 껍데기를 벗기고 순정 배열 자체를 순회합니다.
																	foreach ($result_etf as $etf_value) {
																		$tt++;

																		// ==========================================================
																		// [로직부] HTML을 그리기 전, 필요한 변수와 스타일을 미리 계산합니다.
																		// ==========================================================
																		
																		// 1-1. 구성 종목(holdings_count) 태그 조립
																		if (empty($etf_value['holdings_count'])) {
																			// 구성 종목이 없으면 입력 팝업창 띄우기
																			$holdings_url = $cur_php . make_url(['opt' => 'holdings_insert']);
																			$naver_url = "https://finance.naver.com/item/coinfo.naver?code=" . $etf_value['etf_code'];
																			$holdings_count_tag = "<a href='{$holdings_url}' onclick=\"window.open('{$naver_url}', 'pop_naver', 'width=1200, height=1200'); return false;\"><img src='img/icn_pen02.gif'></a>";
																		} else {
																			// 구성 종목이 있으면 보기 페이지로 이동
																			$view_url = $cur_php . make_url(['opt' => 'holdings_view', 'etf_code' => $etf_value['etf_code']]);
																			$holdings_count_tag = "<a href='{$view_url}'>" . $etf_value['holdings_count'] . "</a>"; 
																		}

																		// 1-2. 현재 선택된 ETF 하이라이트 처리
																		$etf_tr_color = "";
																		if (!empty($GR_Vals['etf_code']) && $GR_Vals['etf_code'] == $etf_value['etf_code']) {
																			$etf_code_tags = "<font style='color:red; font-weight:bold; font-size:17px; background-color:yellow;'>" . $etf_value['etf_name'] . "</font>"; // 🚨 누락된 </font> 추가
																			$etf_tr_color = "style='background-color:yellow;'";
																		} else {
																			if (!empty($etf_value['holdings_count'])) {
																				$etf_code_tags = "<font style='color:black; font-weight:bold;'>" . $etf_value['etf_name'] . "</font>";
																			} else {
																				$etf_code_tags = $etf_value['etf_name']; 
																			}
																		}

																		// ==========================================================
																		// [출력부] 계산된 변수를 바탕으로 깔끔하게 HTML을 그립니다.
																		// ==========================================================
																		
																		if (!empty($GR_Vals['uDate'])) {
																			// [A] 특정 날짜 시세 조회 모드
																			$trading_val_hundred_mil = number_format($etf_value['trading_value'] / 100); // 억 단위
																			
																			echo "<tr height='35' style='text-align:center;'>
																					<td>{$tt}</td>
																					<td style='text-align:left;'>{$etf_code_tags}</td>
																					<td>" . deco_txt($etf_value['return_rate'], 11, 0) . "</td>
																					<td>" . deco_txt($etf_value['etf_rate'], 11, 0) . "</td>
																					<td>" . number_format($etf_value['turnover_ratio'], 2) . "</td>
																					<td style='text-align:right;'>{$trading_val_hundred_mil} 억 &nbsp;</td>
																					<td style='font-size:13px;'>{$etf_value['Listing_Date']}</td>
																					<td style='font-size:13px; width:250px; text-align:left;'>{$etf_value['underlying_index']}</td>
																					<td style='text-align:center;'>{$holdings_count_tag}</td>
																				  </tr>";
																		} else {
																			// [B] ETF 기초 정보 모드
																			$total_nav_fmt = number_format($etf_value['total_nav'] ?? 0);
																			$swap_tag = $swap_tag ?? ""; // 기존 코드에서 넘어오는 변수 방어
																			
																			echo "<tr height='35' {$etf_tr_color} style='text-align:center;'>
																					<td>{$tt}</td>
																					<td style='text-align:left;'>{$etf_code_tags}</td>
																					<td>{$total_nav_fmt}</td>
																					<td>" . deco_txt($etf_value['return_rate'], 11, 0) . "</td>
																					<td style='font-size:13px;'>{$etf_value['Listing_Date']}</td>
																					{$swap_tag}
																					<td style='font-size:13px;'>{$etf_value['classification']}</td>
																					<td style='font-size:13px; width:250px; text-align:left;'>{$etf_value['underlying_index']}</td>
																					<td>{$etf_value['volatility']}</td>
																					<td style='text-align:center;'>{$holdings_count_tag}</td>
																				  </tr>";
																		}

																		// 구분선 출력
																		echo "<tr>" . ($dot_line ?? '') . "</tr>";
																	}

							   echo  "</table> "; # start of 1번째  tbl
																					
echo "</td><td width=650px; style='vertical-align:top;'>"; // 우측

	   echo  "<table style='border: 1px dashed orange; border-radius: 10px; background-color:white; border-spacing:5px;' > "; # start of 1번째  tbl

					##  etf에 등록된 종목 입력, 보기

					if($GR_Vals['opt']=='holdings_insert')  { #시작: 입력창

					$tr_text_input= "<tr style='vertical-align: top;'>
													<td>
															   <form method=post action=\"$cur_php\" enctype='multipart/form-data' name=myform>	
															   <input type=\"hidden\" name=\"mode\" value=\"etf_list\">
															   <input type=\"hidden\" name=\"uDate\" value=\"".$GR_Vals['uDate']."\">
															  <input type=\"hidden\" name=\"opt\" value=\"holdings_update\">
															  <input type=\"hidden\" name=\"etf_code\" value=\"".$GR_Vals['etf_code']."\">
													</td>
										  </tr>

											<tr align=\"left\">
													 <td align='left' style='padding-top:15px;'>     																			
														<input type=submit value='등 록' class=form_nc style='width:80px;cursor:hand;'>                    																			
												     </td>
											</tr>";

				$tr_text_input.= "<tr><td ><textarea name=etf_holdings_cts style=\"width:400px; height:1000px;\"></textarea></form></td></tr>";

				echo $tr_text_input;

				exit;

					} # 끝. 입력창
			
			else if($GR_Vals['opt']=='holdings_update') { 

									  $etf_ori_vals=explode("\n",preg_replace("/[,]/i", "", $GR_Vals['etf_holdings_cts'])); # 불필요한 특수문자들 제거후
									  $chunked_lines = array_chunk($etf_ori_vals, 2);
									  $stock_array = [];

									 foreach ($chunked_lines as $item) {    

													  $stock_name = trim($item[0]); 

											            if(!$stock_name || $stock_name=="원화현금" ) continue;

														$h_cnt++;
													
													$holdings_weight = explode("\t", trim($item[1]))[1]; 
													$get_stock_code=get_stock_name_info($stock_name,$connect);

												 	 $query_str="insert into all_etf_holdings_info set etf_code='".$GR_Vals['etf_code']."', stock_code='".$get_stock_code['stock_code']."', stock_name='".$stock_name."', holdings_ratio='".$holdings_weight."',uDate = CURDATE() ";
													 $result_ins=mysqli_query($connect,$query_str); 

													## etf 종목   

											  } 

										  # 갯수 업데이트 해줄것 all_etf_info

										$query_up = "UPDATE all_etf_info   SET holdings_count =$h_cnt    WHERE etf_code = '".$GR_Vals['etf_code']."'";
										$result_up=mysqli_query($connect,$query_up); 

						#$target_url = "$cur_php?mode=etf_list&opt=holdings_view&etf_code={$GR_Vals['etf_code']}&uDate={$GR_Vals['uDate']}";
#						$target_url="$cur_php.php?mode=etf_list&opt=holdings_view&etf_code='".$GR_Vals['etf_code']."'&uDate='".$GR_Vals['uDate']." ' ";


										echo "<script>
												    location.href='".$cur_php.make_url(['opt'=>'holdings_view'])."';
										</script>";

										exit;

			} # end of holdings_update


			else if($GR_Vals['opt']=='holdings_view')   { # holdings_view 입력이 아닌 db에서 join으로 데이터 불러오기, 이후 태그를 만들것

				     $target_key='all_stock_info';										   
					 $last_update=data_upTime($target_key, 'call',$pdo);

							$holding_cts="
							<tr style='text-align:right;font-size:14px;'><td colspan=10>update by ".$last_update."</td></tr>
							<tr style='background-color:yellow;text-align:center;'><td width=150px;>종목명</td><td>편입비중</td><td>현재가</td><td>등락률</td><td  width=100px;>거래대금(억)</td><td>회전율</td><td width=80px;>시총(억)</td></tr>";

					$query_etf="
												SELECT 
													B.stock_name,           -- 종목명
													C.etf_count,            -- 편입 ETF 갯수 (서브쿼리에서 가져옴)
													A.stock_code,
													A.holdings_ratio,       -- 편입비중
													B.stock_price,          -- 종목현재가
													B.stock_rate,           -- 종목등락률
													B.stock_vol_cap,        -- 거래대금
													B.stock_cap             -- 시총
												FROM all_etf_holdings_info A
												INNER JOIN all_stock_info B 
													ON A.stock_code = B.stock_code
												LEFT JOIN (
													SELECT 
														stock_code, 
														COUNT(etf_code) AS etf_count 
													FROM all_etf_holdings_info 
													GROUP BY stock_code
												) C ON A.stock_code = C.stock_code

												WHERE A.etf_code = '{$GR_Vals['etf_code']}'
												ORDER BY A.holdings_ratio DESC;
												";
											
											#print_r($query_etf);

				$result_etf=mysqli_query($connect,$query_etf); 

					foreach($result_etf as $etf_key => $etf_value){

#						{$etf_value['']}


                         $stock_target_url="<a href='#' onclick=\"window.open('https://finance.naver.com/item/main.naver?code=".$etf_value['stock_code']."', 'pop_naver', 'width=1200, height=1200');\">";

						 $vol_ratio=number_format($etf_value['stock_vol_cap']/$etf_value['stock_cap'],2);
				
						$holding_cts.="<tr style='text-align:right'><td style='text-align:left'>$stock_target_url{$etf_value['stock_name']}</a> (#{$etf_value['etf_count']})</td>
						<td >{$etf_value['holdings_ratio']}</td>
						<td>".number_format($etf_value['stock_price'])."</td><td>".deco_txt($etf_value['stock_rate'],11,0)."</td><td>".number_format($etf_value['stock_vol_cap'])."</td><td>$vol_ratio</td><td>".number_format($etf_value['stock_cap'])."</td></tr>";

						}

		echo $holding_cts;

}



		             



	   echo "</table>"; # 끝 우측 테이블





echo "</td></tr></table>";



echo "</body></html>";

exit;


#################################################################
} # end of thema_list($connect)
#################################################################


#################################################################
function all_etf_update ($pdo)  {  # 전 etf 정보를 업데이트 함
#################################################################
global $cur_php;
    require_once "./env/inf.fnc"; // _once 사용 권장
    require_once "./env/e.fnc";

    $test_on = 0; // 1로 바꾸면 DB에 넣지 않고 화면에 출력만 함

    // 1. 업로드 파일 방어 로직
    if (!isset($_FILES['upfile']) || $_FILES['upfile']['error'] !== UPLOAD_ERR_OK) {
        echo "<script>alert('파일이 없거나 업로드 중 오류가 발생했습니다.'); history.back();</script>";
        return;
    }

    // 2. PDO 쿼리 준비 (단 한 번만 준비하면 됩니다. 속도 극대화)
    // 💡 마법의 문법: ON DUPLICATE KEY UPDATE (기본키가 중복되면 자동으로 UPDATE 실행)
    $sql = "INSERT INTO all_etf_info 
            (etf_code, etf_name, Listing_Date, classification, return_rate, underlying_index, total_nav, volatility) 
            VALUES (:code, :name, :date, :class, :rate, :index, :nav, :vol)
            ON DUPLICATE KEY UPDATE 
            etf_name = VALUES(etf_name),
            classification = VALUES(classification),
            return_rate = VALUES(return_rate),
            underlying_index = VALUES(underlying_index),
            total_nav = VALUES(total_nav),
            volatility = VALUES(volatility)";

    $stmt = $pdo->prepare($sql);

    // 3. 파일 열기
    $tmp_name = $_FILES['upfile']['tmp_name'];
    $file_open = fopen($tmp_name, "r");
    
    if ($file_open) {
        // 4. 파일을 한 줄씩 읽으면서 그 자리에서 바로 DB에 꽂아 넣습니다.
        while (($line = fgets($file_open)) !== false) {
            
            $line_utf8 = iconv("EUC-KR", "UTF-8//IGNORE", $line);
            $clean_line = str_replace("\"", "", trim($line_utf8));
            if (empty($clean_line)) continue;

            // 콤마로 데이터 쪼개기
            $val = explode(",", $clean_line);

            // [0]종목코드 [1]종목명 [2]상장일 [3]분류 [5]수익률 [6]기초지수 [8]순자산 [10]변동성
            $total_nav_billion = $val[8] / 100000000; // 순자산 1억 단위로 통일

            if ($test_on) {
                echo "코드: {$val[0]} | 이름: {$val[1]} | 순자산: {$total_nav_billion}억 <br>";
                continue; // 테스트 모드일 때는 DB INSERT 생략
            }

            // 5. 실행 (DB 해킹 완벽 차단 및 초고속 실행)
            try {
                $stmt->execute([
                    'code'  => $val[0],
                    'name'  => $val[1],
                    'date'  => $val[2],
                    'class' => $val[3],
                    'rate'  => $val[5],
                    'index' => $val[6],
                    'nav'   => $total_nav_billion,
                    'vol'   => $val[10]
                ]);
            } catch (PDOException $e) {
                echo "<font color=red>데이터 입력 오류 (코드: {$val[0]}): " . $e->getMessage() . "</font><br>";
                exit; // 에러 발생 시 즉시 중단
            }
        } // end of while
        
        fclose($file_open);
        
        // 6. 업데이트 시간 기록 (기존 함수 그대로 사용)
        $target_key = 'all_etf_info';           
        data_upTime($target_key, 'update', $pdo);

        echo "<script>alert('전체 ETF 시세 업데이트가 초고속으로 완료되었습니다.');</script>";
        // echo "<meta http-equiv=\"refresh\" content=\"1;url=prj_yehior.php?mode=asl_pu\">";

    } else {
        echo "<script>alert('파일을 열 수 없습니다.'); history.back();</script>";
    }

#################################################################
} # all_etf_update ($adminID,$upfile,$connect)
#################################################################

#################################################################
function all_etf_price_update ($connect,$pdo)  {  # 전 etf 시세를 업데이트 함
#################################################################
global $cur_php;
require "./env/inf.fnc";
require "./env/e.fnc";

$test_on=0;

$GR_Vals=Get_Vals('mode');

$file_open=fopen($_FILES['upfile']['tmp_name'],"r");
$file_Bytes=$_FILES['upfile']['size'];

$file_cts=fread($file_open,$file_Bytes);

$file_cts = iconv("EUC-KR", "UTF-8", $file_cts);
$file_cts=str_replace("\"","", $file_cts);
$file_line_cts = explode("\n", $file_cts);


$up_day=explode(" ",$GR_Vals['up_day2']);


$query_del="delete from all_etf_price  where is_locked=0";
$result_del=mysqli_query($connect,$query_del); 



 foreach($file_line_cts as $key=>$all_etf_price) {

							  if(empty($key)) continue;

					  		 $all_etf_value=explode(",",$all_etf_price);

							if($test_on) print_r($all_etf_value);

							# [0] => 종목코드    [2] => 종가  [4] => 등락률   [10] => 거래대금    [11] => 시가총액

									 $trading_value=$all_etf_value[10]/1000000; # 거래대금(백만)
 									 $market_cap=$all_etf_value[11]/100000000; # 시가총액(억)

									 $turnover_ratio= $trading_value/$market_cap*100;

                                 	 $query_str="insert into all_etf_price set etf_code='".$all_etf_value[0]."', etf_price='".$all_etf_value[2]."', etf_rate='".$all_etf_value[4]."', trading_value='".$trading_value."',market_cap='".$market_cap."',turnover_ratio='".$turnover_ratio."',uDate='".$up_day[0]."'   ";
						
														if($test_on) echo $query_str."<br>";

													  $alert_msg="etF 시세의 업데이트가 완료되었습니다.";

														
									$result_ins=mysqli_query($connect,$query_str); 
																
                                                          if(! $result_ins ) {  echo "<font color=red>~~~~~~~~~~~~~~~~ Could not update data~~~~~~~~~~~!!!!!!!! <br>$query_str<br>"   ;  

														                                     $err_code=1;

																							 exit;
														  
														                              } 



		 } # end of foreach

#
									  # etf 시세를 입력한 시간
									       $target_key='all_etf_price';										   
										  data_upTime($target_key, 'update',$pdo);



if($test_on) exit;

if(!$err_code) {

#    prj_analysis 에도 데이터 반영할 것

							echo "	  <meta http-equiv=\"refresh\" content=\"1;url=prj_yehior.php?mode=asl_pu\"> ";

} else   echo " 오류가 발생했습니다.";

#################################################################
} # all_etf_price_update ($adminID,$upfile,$connect)
#################################################################




# mysql값을 가져옴
################### start    of mysql_Query #######################
 function   get_daily_tr_info($vals,$connect) { 
################### start    mysql_Query #######################
  
  #
  

if($vals['type']=='trade_review') {

		$qry="SELECT  no,profit,profit_rate,buy_price,sell_price from `tbl_trade_review`  where no='".$vals['no']."'";

	}

elseif($vals['type']=='trade_multi_view') {

		$qry="SELECT  no,profit,profit_rate,buy_price,sell_price,sell_cap,acc_no from `tbl_trade_review`  where stock_code='".$vals['stock_code']."' and sell_Date='".$vals['uDate']."'";

}
																																		
  
  $result=mysqli_query($connect, $qry); 


	if($vals['type']=='trade_review') {

                            $get_daily_tr_info = mysqli_fetch_array($result);

						if($get_daily_tr_info['no']) $tr_info_tag="<img src='../img/buy.png'>".deco_txt($get_daily_tr_info['buy_price'],3,0)." &nbsp; <img src='../img/sell.png'>  ".deco_txt($get_daily_tr_info['sell_price'],3,0)." &nbsp; (손익) ".deco_txt($get_daily_tr_info['profit'],122,0)." &nbsp; (수익률) ".deco_txt($get_daily_tr_info['profit_rate'],13,0)."";

	}

elseif($vals['type']=='trade_multi_view') {

	 $tr_info_tag="<table style='border: 1px dashed orange; border-radius: 7px; ; border-spacing:3px;font-size:12px;background:white;' width=100% border=0>";

  foreach ($result as  $tm => $tm_value) {  
	  $tot_profit+=$tm_value['profit'];
  	  $tot_sell_cap+=$tm_value['sell_cap'];

	  $tr_info_tag.="<tr> <td><a href='prj_yehior.php?mode=tdv&pop=view&no=".$tm_value['no']."' target='news_d5'><img src='../img/rep.gif'></a></td><td width=40px;>대금(".$tm_value['acc_no'].")</td><td align=right width=60px;>".deco_txt($tm_value['sell_cap'],3,0)." </td>";
	  
	  if($vals['detail']) $tr_info_tag.="<td><img src='../img/buy.png'></td><td>".deco_txt($tm_value['buy_price'],3,0)." </td><td><img src='../img/sell.png'></td><td>".deco_txt($tm_value['sell_price'],3,0)." </td>";
	  
	  $tr_info_tag.="<td> (손익) ".deco_txt($tm_value['profit'],122,0)." (".deco_txt($tm_value['profit_rate'],13,0).")</td></tr>";	

	}

	$get_daily_tr_info=$tm_value;
     
	 if($tm>0) 		 $tr_info_tag.="<tr><td></td><td  align=right>".deco_txt($tot_sell_cap,3,0)."</td><td colspan=4> &nbsp; ".deco_txt($tot_profit,122,0)." (".deco_txt($tot_profit/($tot_sell_cap-$tot_profit)*100,13,0).")</td><td> </td><td></td></tr>";
	
$tr_info_tag.="</table>";

}




   return  array('value'=>$get_daily_tr_info,'tag'=>$tr_info_tag);			
   
################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################			



# mysql값을 가져옴
################### start    of mysql_Query #######################
 function   pop_go_to_url($connect) { 
################### start    mysql_Query #######################

global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');


if($GR_Vals['stock_name']) { 
	$stock_info=get_stock_name_info($GR_Vals['stock_name'],$connect);
	$GR_Vals['key_word']=$GR_Vals['stock_name'];
}

elseif($GR_Vals['stock_code']) { 
	$stock_info=get_stock_info($GR_Vals['stock_code'],$connect);
	$GR_Vals['key_word']=$stock_info['stock_name'];
}

  if(empty($stock_info['stock_code'])) {  # 종목코드 또는 종목명으로 검색해도 정보가 없다면, 테마이름에서 찾기
		   $thema_qry="select * from tbl_thema_name where   thema_name LIKE '%".$GR_Vals['key_word']."%' limit 0,1 ";																											
		  $result_thema=mysqli_query($connect, $thema_qry); 
		  $get_thema_info = mysqli_fetch_array($result_thema);
		  $GR_Vals['thema_no']=$get_thema_info['thema_no'];  
	
  }

   $news_title=rawurlencode($GR_Vals['news_title']);
   $news_link=$GR_Vals['news_link'];


	$stock_code= 	$stock_info['stock_code'];
	$stock_name= 	$stock_info['stock_name'];
   $thema_no=$GR_Vals['thema_no'];

   	$key_word= 	$GR_Vals['key_word'];
  
   $pop_type=$GR_Vals['pop_type'];

  $stock_no=$GR_Vals['stock_no'];


echo ("
		   <script type=\"text/javascript\">

				function    go_opener(pop_type) {  

					 var get_thema_no='$thema_no';

						const open_pop = pop_type.split('');
						

					   if(get_thema_no) { 
														   open_pop[4]=1; 
													//	   open_pop[3]=2; 
													}


                          if(open_pop[0]==1)  {  opener.parent.frames['news_d1'].location='$cur_php?mode=search&key_word=$key_word';
                                          					   opener.parent.frames['news_t1'].location='$cur_php?mode=gt&type=t1&key_word=$key_word';
														 }

							 else if(open_pop[0]==2)  {  	

																		//	alert(1);

																			var news_title_encode='$news_title';
																			var news_link_encode='$news_link';

																			var news_title=decodeURIComponent(news_title_encode);
																		 	var news_link=decodeURIComponent(news_link_encode);


																			//alert(news_title);

								   
																		 opener.parent.frames['news_d2'].document.getElementById('news_title').value=news_title;	
														                 opener.parent.frames['news_d2'].document.getElementById('news_link').value=news_link;																		
																	
                                
															 }


                          else if(open_pop[0]==3)  {  	


									                                  opener.parent.frames['s1'].location.reload();	
																	  opener.parent.frames['s2'].location='$cur_php?mode=sal_view&stock_no=$stock_no';																							;	
																	  self.close();

																		 }

					       else if(open_pop[0]==4)  {  	


									                                 // opener.parent.frames['s1'].location.reload();	
																	  opener.parent.frames['s2'].location.reload();	
																	  opener.parent.frames['s3'].location='prj_yehior.php?mode=tdv&pop=si&no=$stock_no';																							;	
																	  self.close();

																		 }




						
						  if(open_pop[3]==1)  { 	
							                                   	var stock_name='$stock_name';
							  
							                                     opener.parent.frames['news_d4'].location='$cur_php?mode=stock_all&stock_code=$stock_code';	
									  						      //
																 // opener.parent.opener.parent.frames['news_t3'].location='$cur_php?mode=top_pi_history&stock_code=$stock_code';																							


																  opener.parent.frames['news_t3'].location='$cur_php?mode=top_pi_history&stock_code=$stock_code';																							
                                         						 // opener.parent.frames['news_t4'].location='prj_yehior.php?mode=ashv&stock_code=$stock_code';																																							
																  opener.parent.frames['news_d2'].document.getElementById('rel_stock_1').value=stock_name;	
															 }

						 else if(open_pop[3]==2)  {  opener.parent.frames['news_d4'].location='$cur_php?mode=thema_manaGe&key_word=$key_word';																													 							
                                
															 }

						  if(open_pop[4]==1)  {  
                                    							  opener.parent.frames['news_d5'].location='$cur_php?mode=thema_all&no=$thema_no#$thema_no';																							
                                         						  
															 }
						 else if(open_pop[4]==2)  {  
						                                       	 opener.parent.frames['news_d5'].location='$cur_php?mode=stock_all&stock_code=$stock_code';																							
                                
															 }




						  self.close();

							   }

			</script>	  
			
	");

	
echo "<body  onload=javascript:go_opener('$pop_type');>";

  

################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################			




################### start    of mysql_Query #######################
 function   tick_time_gap_calc($start,$end) { 
################### start    mysql_Query #######################

       $gap_time=$end-$start;

        $hour_gap=floor($end/100)-floor($start/100);

        


	   #if($gap_time<=100 and $gap_time>60  ) $minus_time=40;
		#   else $minus_time=floor($gap_time/100)*40;

	   $tick_time_gap_calc=$gap_time-$hour_gap*40;


      
 return $tick_time_gap_calc;
################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################			





# mysql값을 가져옴
################### start    of mysql_Query #######################
 function   get_select_tags($vals,$connect) { 
################### start    mysql_Query #######################
  
 
if($vals['db_str']=="stock_stg") { 

  $all_cat=all_category_tag($vals['db_str'],$connect);
  $vals_array=$all_cat;

  $checked[$vals['dft']]="checked";

 $font_tag[$vals['dft']]="style='color:red;font-weight:bold;font-size:14px;' ";

}

$gs_tags="<table style='border: 1px dashed orange; border-radius: 10px; background-color:#EFF2FB; border-spacing:0px;padding:5px;font-size:12px;'  align=left border=0><tr>";
foreach($vals_array as $g_no => $g_value)$gs_tags.="<td width=nowrap ".$font_tag[$g_no]."><input type=radio name='".$vals['tag_name']."' value='".$g_no."' ".$checked[$g_no]." onclick=\"location.href='".$cur_php."?mode=sal&stg_type=".$g_no."' \" >".$g_value." ( #".$vals['cnt'][$g_no]." )</td>";
$gs_tags.="<td><a href='../prj_yehior.php?mode=cm&cat_type=stock_stg'><img src='../img/plus.gif'style='cursor:hand' title='신규 등록'></td>";
$gs_tags.="</tr></table>";

   return  array('tag'=>$gs_tags,'tag_array'=>$vals_array);			
   
################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################			







################### start of Get_analysis_Date #######################
function      Get_analysis_Date($unix_time,$Day_Arr) {  # 영업일 기준 날짜를 구함

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



################### start    of mysql_Query #######################
 function all_category_tag($db_str,$connect) { 
################### start    mysql_Query #######################

# 기존것 삭제
  $query="select * from tbl_cat where cat_type='".$db_str."' order by cat_ord_no " ;
  $result=mysqli_query($connect,$query);     


while($all_cat = mysqli_fetch_array($result, MYSQLI_ASSOC)){

    $return_cat[$all_cat['no']]=$all_cat['cat_str'];

}

return $return_cat;



################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################


################### start    of mysql_Query #######################
 function dn_file($connect) { 
################### start    mysql_Query #######################

global $cur_php;
global $tbl_width;

require "./env/e.fnc";
require "./env/inf.fnc";

$GR_Vals=Get_Vals('mode');


 $interval_days=5;


# if(!$GR_Vals['stg_type']) $GR_Vals['stg_type']=16;

 $file_name="전략종목_".$GR_Vals['stg_type']."_".date("ymd").".csv";

# 타입이 없으면 상한가 16 으로 고정

 	$where_qry="where stg_type='".$GR_Vals['stg_type']."' and DATE_Add(uDate_5,INTERVAL ".$interval_days." day)>='".$today."' ";

	$qry_stock="SELECT  *  from `tbl_stock_analysis`  ".$where_qry." order by ".$udate_qry."(stock_price/stg_price) desc  ";										
   	$result_stock=mysqli_query($connect,$qry_stock); 

$aa= "종목코드,종목명\n";
  
   foreach($result_stock as $st=>$st_value)   {  # # start of srt_array

    $stock_info=get_stock_info($st_value['stock_code'],$connect);  

  $aa.= "'".$st_value['stock_code'].",".$stock_info['stock_name']."\n";

   }

$ac = iconv("UTF-8","EUC-KR",($aa));
print_r($ac);




#$filename = "csvoutput_".$date.".csv";
header("Content-Disposition: attachment; filename=$file_name");
header("Content-Transfer-Encoding: binary");
 Header("Content-Description: PHP3 Generated Data"); 
#echo $csv_dump;


################### end    mysql_Query#######################     
}
################### end    mysql_Query#######################

################### start    get_naver_etf_info #######################
function get_naver_etf_info($etf_code,$pdo) { 
################### start    get_naver_etf_info #######################

$api_url = "https://navercomp.wisereport.co.kr/v2/ETF/index.aspx?cmp_cd=".$etf_code."&target=cu_more";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $api_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

// 와이즈리포트 보안 통과용 헤더
$headers = [
    "Accept: text/html, */*; q=0.01",
    "Referer: https://finance.naver.com/",
    "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36",
    "X-Requested-With: XMLHttpRequest"
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$html = curl_exec($ch);
curl_close($ch);

echo "<h3>✅ 편입종목 JSON 데이터 추출 성공!</h3>";
echo "<pre>";

// 1. 💡 [핵심] 정규식(Regular Expression)으로 var CU_data = { ... }; 부분만 도려냅니다.
// /is 옵션은 줄바꿈이 있어도 끝까지 찾으라는 뜻입니다.
preg_match('/var CU_data = (\{.*?\});/is', $html, $matches);

// 2. 매칭된 데이터가 있는지 확인합니다.
if (isset($matches[1])) {
    
    // 3. 텍스트 형태의 JSON을 PHP 배열로 한 방에 변환!
    $json_string = $matches[1];
    $data_array = json_decode($json_string, true);
    
    // 4. 'grid_data' 안에 있는 종목 리스트를 가져옵니다.
    $stock_list = $data_array['grid_data'];
    
    // 5. 한 줄씩 돌면서 예쁘게 출력합니다.
    foreach ($stock_list as $item) {
        
        $stock_name = $item['STK_NM_KOR']; // 종목명
        $weight     = $item['ETF_WEIGHT']; // 비중
        $stock_cnt  = $item['AGMT_STK_CNT']; // 주식수
        
        // 원화현금(예수금) 같은 항목은 제외하고 실제 주식만 출력
        if ($stock_name != '원화현금') {
            echo "종목명: {$stock_name} \t 비중: {$weight}% \t 주식수: {$stock_cnt}주\n";
            
            // 💡 실무 적용 팁: 여기서 바로 DB로 INSERT 하시면 됩니다!
            /*
            $insert_query = "INSERT INTO all_etf_holdings_info ...";
            mysqli_query($conn, $insert_query);
            */
        }
    }
} else {
    echo "❌ 스크립트에서 CU_data를 찾지 못했습니다.";
}

echo "</pre>";

################### end    get_naver_etf_info #######################     
}
################### end   get_naver_etf_info #######################


?>




