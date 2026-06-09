<?
# DB연결
require "./env/cnt.inc";
# error 표시
error_reporting( E_ALL &~E_NOTICE); # 
ini_set("allow_url_fopen",1);
ini_set("display_errors", 1);




#변수정의
 $mode = $_REQUEST['mode'];
if(!$mode) $mode='write';

$cur_php = basename($_SERVER['PHP_SELF']);


if($mode=='write')              { Finance_info_Write ($connect); }

	elseif($mode=='read')              { Finance_info_Read ($connect); }

	elseif($mode=='update')         { Finance_info_update($connect); }


else  {  echo "<script language=\"javascript\">
					alert(\" Version : $ver \");
					</script>    			
					";			
			}

mysqli_close($connect);



############################################
function Finance_info_Write($connect) {
###########################################
require "./env/e.fnc";
require "./env/inf.fnc";
include "shd/simple_html_dom.php";  

# 변수정의
global  $cur_php;
$GR_Vals=Get_Vals('mode');



/*

#import requests
#session = requests.Session()
headers = {
  #  "User-Agent':'Mozilla/5.0 (Windows NT 6.3; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/63.0.3239.132 Safari/537.36",
    "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*
html = session.get(WIKI_URL, headers=headers).content
bsObj = BeautifulSoup(html, "html.parser”)

*/


### 1) 시작 : 네이버 투자주체별 매매현황


   $cur_time=  calender_str(1,1,time());
#   print_r($cur_time);

			$url_array=array("https://finance.naver.com/sise/investorDealTrendDay.naver?bizdate=".$cur_time['full_mktime']."&sosok=01","https://finance.naver.com/sise/investorDealTrendDay.naver?bizdate=".$cur_time['full_mktime']."&sosok=02","https://finance.naver.com/sise/sise_deposit.naver");

			$hdr =  "Mozilla/5.0 (Windows NT 6.3; Win64; x64) Chrome/63.0.3239.132 Safari/537.36";

			$html = new simple_html_dom(); // Create a DOM object




	 foreach($url_array as $u_keys=>$urls) {

										  $get_html=connect_Http($urls,"GET",null);

										  $get_html = mb_convert_encoding($get_html, "UTF-8", "CP949");
											 
										  $html->load($get_html['body']); 


											foreach($html->find('td') as $key=>$link_res) {											  
																					
																																		 $cts = $link_res->plaintext;

																						if($key>0 && $key<12 ) {    $cts=str_replace( ',' , '',$cts ); # ,제거																						  																							 																						                                                      

                                              												                                                  $Get_inv[$u_keys][] = $cts; 																																			  
																																			  
																														  }

																				}

 
	} # end of foreach


# print_r($Get_inv);

														$tuja_juche_tags="<table border=0 cellpadding=\"5\" cellspacing=\"2\" width=\"560\" style=\"border-collapse: collapse;width:420pt\">
																								 <colgroup><col width=\"128\">1. 투자주체별 매매현황 </colgroup>

																	 <tbody align=center class=tt7>
																					 <tr height=\"30\" >
																					  <td rowspan=\"2\">일자</td>
																					  <td rowspan=\"2\" >　</td>
																					  <td rowspan=\"2\" >개인</td>
																					  <td rowspan=\"2\" >외국인</td>
																					  <td colspan=\"3\" >기관계</td>
																					 </tr>
																					 
																					 <tr height=\"30\"  class=tt7>
																					  <td height=\"30\" >합계</td>
																					  <td >금융투자</td>
																					  <td >연기금</td>
																					 </tr>";


														$tuja_juche_tags.= "						 
																					 <tr height=\"40\"   class=tt8 style='font-size:14px;'>
																					  <td rowspan=\"2\" ><input type='hidden' name='uDate'  value='". $Get_inv[0][0]."'>". $Get_inv[0][0]."</td>";


														$tuja_juche_tags.="
																					  <td >코스피</td>
																					  <td ><input type='hidden' name='kospi_priv'  value='". $Get_inv[0][1]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[0][1]),1,0) ."</td>
																					  <td ><input type='hidden' name='kospi_fore'  value='". $Get_inv[0][2]."' size='8' readonly class=form_nc>". deco_txt($Get_inv[0][2],1,0)."</td>
																					  <td ><input type='hidden' name='kospi_corp'  value='". $Get_inv[0][3]."' size='8' readonly class=form_nc>". deco_txt($Get_inv[0][3],1,0)."</td>
																					  <td ><input type='hidden' name='kospi_corp_fin'  value='". $Get_inv[0][4]."' size='8' readonly class=form_nc>". deco_txt($Get_inv[0][4],1,0)."</td>
																					  <td ><input type='hidden' name='kospi_corp_pen'  value='". $Get_inv[0][9]."' size='8' readonly class=form_nc>". deco_txt($Get_inv[0][9],1,0)."</td>
																					 </tr>
																					 
																					 <tr height=\"30\"   class=tt8 style='font-size:14px;'>
																					  <td height=\"30\"  >코스닥</td>
																					  <td ><input type='hidden' name='kosdaq_priv'  value='". $Get_inv[1][1]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[1][1]),1,0)."</td>
																					  <td ><input type='hidden' name='kosdaq_fore'  value='". $Get_inv[1][2]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[1][2]),1,0)."</td>
																					  <td ><input type='hidden' name='kosdaq_corp'  value='". $Get_inv[1][3]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[1][3]),1,0)."</td>
																					  <td ><input type='hidden' name='kosdaq_corp_fin'  value='". $Get_inv[1][4]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[1][4]),1,0)."</td>
																					  <td ><input type='hidden' name='kosdaq_corp_pen'  value='". $Get_inv[1][9]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[1][9]),1,0)."</td>
																					 </tr>
																	 </tbody>
														 </table>";




														$stock_money_flow_tags="<table border=0 cellpadding=\"5\" cellspacing=\"3\" width=\"560\" style=\"border-collapse: collapse;width:420pt\">
																								 <colgroup><col width=\"128\">2. 증시 자금동향</colgroup>

																	 <tbody align=center>
																					 <tr height=\"30\"  class=tt7>
																					  <td rowspan=\"2\">일자</td>

																					  <td rowspan=\"2\"  colspan=2>고객예탁금</td>
																					  <td rowspan=\"2\" colspan=2>신용잔고</td>
																					  <td colspan=\"3\" >펀드</td>
																					 </tr>
																					 
																					 <tr height=\"30\"  class=tt7 >
																					  <td height=\"30\" >주식형</td>
																					  <td >혼합형</td>
																					  <td >채권형</td>
																					 </tr>";


														$stock_money_flow_tags.="        <tr height=\"40\"  class=tt8 style='font-size:14px;'> 
																													   <td>". $Get_inv[2][0]."</td>
																													  <td ><input type='hidden' name='custom_deposit'  value='". $Get_inv[2][1]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[2][1]),3,0)."</td>
																													  <td ><input type='hidden' name='kospi_priv'  value='".$Get_inv[2][2]."' size='5' readonly class=form_nc>".deco_txt(($Get_inv[2][2]),1,0)."</td>

																													  <td ><input type='hidden' name='kospi_fore'  value='". $Get_inv[2][3]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[2][3]),3,0)."</td>
																													  <td ><input type='hidden' name='kospi_priv'  value='". $Get_inv[2][4]."' size='5' readonly class=form_nc>". deco_txt(($Get_inv[2][4]),1,0)."</td>

																													  <td ><input type='hidden' name='kospi_corp'  value='". $Get_inv[2][5]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[2][5]),3,0)."</td>
																													  <td ><input type='hidden' name='kospi_corp_fin'  value='". $Get_inv[2][7]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[2][7]),3,0)."</td>
																													  <td ><input type='hidden' name='kospi_corp_pen'  value='". $Get_inv[2][9]."' size='8' readonly class=form_nc>".deco_txt(($Get_inv[2][9]),3,0)."</td>
																													 </tr>
																														
																													 </tbody>
																													 </table>
																										";



### 1) 끝 : 네이버 투자주체별 매매현황







### 2) 한경데이터 세계지수,  환율,금리,원자재

  ########    세계 주요지수, 한국,   원자재.. 까지 하면 될듯.
  #### 오늘 이슈종목 못햇음.. 제길.


   $url_hk_data_array[0]="https://datacenter.hankyung.com/major-indices"; # 세계지수
   $url_hk_data_array[1]=  "https://datacenter.hankyung.com/currencies";  # 환율
   $url_hk_data_array[2]=  "https://datacenter.hankyung.com/rates-bonds";  # 채권금리

   $url_hk_data_array[3]=  "https://datacenter.hankyung.com/commodities";  # 원자재

   

   $hk_data_Title[0] = " ※ 세계 주요지수  &nbsp; <span style='font-size:13px;'>( 출처 :  <a href='$url_hk_data_array[0]' target=\"news2\" >한경 데이터센터</a> )";
   $hk_data_Title[1] = "4.주요국 원화 환율  &nbsp; <span style='font-size:13px;'>( 출처 :  <a href='$url_hk_data_array[1]'  target='news2'>한경 데이터센터</a> )";
    $hk_data_Title[2] = "5. 한국/미국/일본 채권금리 &nbsp; <span style='font-size:13px;'>( 출처 :  <a href='$url_hk_data_array[2]'  target='news2'>한경 데이터센터</a> )";
   $hk_data_Title[3] = "6. 원자재 &nbsp; <span style='font-size:13px;'> ( 출처 :  <a href='$url_hk_data_array[3]'  target='news2'>한경 데이터센터</a> )";


	$hk_in_array[0]=array('나스닥 지수','다우존스 산업지수','S&P 500 지수','독일','영국','프랑스','인도','캐나다 SP TSX','중국상해종합','홍콩 H 지수','NIKKEI 225','대만','베트남 호치민');

	$hk_in_array[1]=array("미국","영국","유로","캐나다","일본","중국","홍콩");
	$hk_in_array[2]=array('CD91일물','CP91일물','국고1년','국고3년','국고5년','국고10년','국고20년','국고30년','국민주택1종','통안1년','통안2년','한전3년','회사채3년','회사채3년BBB-','미국 1년 T-Note','미국 2년 T-Note','미국 3년 T-Note','미국 5년 T-Note','미국 10년 T-NOTE 수익률','미국 30년 T-BOND 수익률','미국 단기우대금리','미국 연방기금금리(콜)','일본 1년 국채 수익률','일본 2년 국채 수익률','일본 3년 국채 수익률','일본 4년 국채 수익률','일본 5년 국채 수익률','일본 6년 국채 수익률','일본 7년 국채 수익률','일본 10년 국채 수익률','일본 20년 국채 수익률','일본 30년 국채 수익률');

	$hk_in_array[3]=array('WTI','브렌트','두바이유 현물','난방유 근월물','금','은','백금','납 3M','니켈','아연 3M','전기동','주석 3M','팔라듐','알루미늄 H/G 3M','알루미늄 H/G 캐시','알루미늄 합금 3M','알루미늄 합금 캐시','귀리 14-07','대두 14-07','대두박 14-07','대두유 14-07','동 14-05','돼지살코기','면화','설탕','소맥 14-07','쌀 14-07','오렌지쥬스','옥수수','커피','코코아 14-07','4등급우유');



				 foreach($url_hk_data_array as $u_keys=>$urls) {

													  $get_html=connect_Http($urls,"GET",null);

													 # $get_html = mb_convert_encoding($get_html, "UTF-8", "CP949");
														 
													  $html->load($get_html['body']); 
																					
																						foreach($html->find('div.table-stock-wrap') as $key=>$link_res) {											  # 표를 찾고

																																							foreach($link_res -> find('table') as $tp=>$link_tv) {


																																									 # 표1 . 현재 데이타 표2. 기간데이타


																																																					foreach($link_tv -> find('tr') as  $tt=>$link_ttv) {

																																																												   $country_tit=$link_ttv->find('a',0)->plaintext;

																																																												   $link_url=$link_ttv->find('a',0)->href;

																																																												   #echo $link_url;

																																																												   #echo "'".$country_tit."',";

																																																												   # in_array($country_tit,$hk_in_array[$u_keys])

																																																												   if(in_array($country_tit,$hk_in_array[$u_keys])) {	

																																																														 $sort_num= array_search($country_tit, $hk_in_array[$u_keys]);  #  기존에 정의한  값들 순서대로 배열에 저장.  원하는 순서대로 정렬하는 효과

																																																														            $Get_Hk_Data_ex_rate[$u_keys][$tp][$sort_num]['urls']= "<a href='$link_url' target='news2'>";
																																																																																																																						   
																																																																						   foreach($link_ttv -> find('td') as  $tts=>$link_td_ttv) {

																																																																											   $find_key=array_search($country_tit, $hk_in_array[$u_keys]);

																																																																												 $Get_Hk_Data_ex_rate[$u_keys][$tp][$sort_num][$tts]=$link_td_ttv->plaintext;

																																																																												# echo "<br>$u_keys $tp $sort_num  $tts :::: $find_n ::: ".$Get_Hk_Data_ex_rate[$u_keys][$tp][$sort_num][$tts];
																																																																									

																																																														 } # end of in_array

																																																					}

																																																					#$Get_info_array[$tp]['title']=$link_tv->find('a',0)->plaintext;
																																																					#echo "<Br><Br><Br>";

																																							

																																							} # end of foreach

																					
																					} # end of foreach


			} # end of foreach


										  # table 1  0: 국가이름  1:구분자 2: 현재가  3: 전일비 4: 전일대비%  8: 시간 
										  # table 2  0: 국가이름  3: 1개월  4: 6개월  5:1년				                                              

	}

# 세계 지수


   $hk_no=0;

						$world_stock_index_tag="<table border=0 cellpadding=\"5\" cellspacing=\"3\" style=\"border-collapse: collapse;width:540pt\"><tr><Td colspan=5>$hk_data_Title[$hk_no]</td></tr><tr class=tt7 align=center><td colspan=2>국가명 </td><td>종가</td><td>전일비</td><td>%</td><td>1개월</td><td>6개월</td><td>1년</td><td>기준일</td></tr>";

												foreach($hk_in_array[$hk_no] as $key=>$value) {

													   $disp_hk_Data=$Get_Hk_Data_ex_rate[$hk_no];
													   

														$world_stock_index_tag.="<tr height=\"40\"><td>".$disp_hk_Data[0][$key]['urls']."$value</a></td>";

															   ## 환율 기호

															      


																if($disp_hk_Data[0][$key][4]<0) $disp_hk_Data[0][$key][3]= $disp_hk_Data[0][$key][3]*(-1);


														$world_stock_index_tag.= "  <td style='font-size:12px;'>".$disp_hk_Data[0][$key][1]."</td>
																										<td  style='font-size:12px;'>".$disp_hk_Data[0][$key][2]."</td> 
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[0][$key][3],12,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[0][$key][4],13,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][3],13,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][4],13,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][5],13,0)."</td>
																										<td style='font-size:11px;'>".$disp_hk_Data[0][$key][8]."</td>																		
																								   ";

														$world_stock_index_tag.="</tr>";

												}

					  $world_stock_index_tag.="</table>";






   # 주요국 환율

   $hk_no=1;

						$world_ex_Rate_tag="<table border=0 cellpadding=\"5\" cellspacing=\"3\" style=\"border-collapse: collapse;width:540pt\"><tr><Td colspan=5>$hk_data_Title[$hk_no]</td></tr><tr class=tt7 align=center><td colspan=2>국가명 </td><td>종가</td><td>전일비</td><td>%</td><td>1개월</td><td>6개월</td><td>1년</td><td>시간</td></tr>";

												foreach($hk_in_array[$hk_no] as $key=>$value) {


															      $disp_hk_Data=$Get_Hk_Data_ex_rate[$hk_no];
														
														$world_ex_Rate_tag.="<tr class=tt8 height=\"40\"><td>".$disp_hk_Data[0][$key]['urls']."$value</a></td>";

																										
																if($disp_hk_Data[0][$key][4]<0) $disp_hk_Data[0][$key][3]= $disp_hk_Data[0][$key][3]*(-1);


														$world_ex_Rate_tag.= "  <td style='font-size:12px;'>".$disp_hk_Data[0][$key][1]."</td>
																										<td>".$disp_hk_Data[0][$key][2]."</td> 
																										<td>".deco_txt($disp_hk_Data[0][$key][3],12,0)."</td>
																										<td>".deco_txt($disp_hk_Data[0][$key][4],13,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][3],13,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][4],13,0)."</td>
																										<td  style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][5],13,0)."</td>
																										<td style='font-size:11px;'>".$disp_hk_Data[0][$key][8]."</td>																		
																								   ";

														$world_ex_Rate_tag.="</tr>";

												}

					  $world_ex_Rate_tag.="</table>";



   # 채권 금리

      $hk_no=2;

						$world_bond_Rate_tag="<div id='hk_data'><table border=0 cellpadding=\"5\" cellspacing=\"3\" style=\"border-collapse: collapse;width:540pt\">
						                                         <tr><Td colspan=5>$hk_data_Title[$hk_no]</td></tr>
																 <tr class=tt7 align=center><td colspan=2 width='210'>상품명 </td><td>종가</td><td>전일비</td><td>%</td><td>1개월</td><td>6개월</td><td>1년</td><td>시간</td></tr>";

												foreach($hk_in_array[$hk_no] as $key=>$value) {


													    $disp_hk_Data=$Get_Hk_Data_ex_rate[$hk_no];

																														if($disp_hk_Data[0][$key][4]<0) $disp_hk_Data[0][$key][3]= $disp_hk_Data[0][$key][3]*(-1);

														$world_bond_Rate_tag.="<tr class=tt8 height=\"40\" style='font-size:14px;'><td  style='font-size:13px;'>".$disp_hk_Data[0][$key]['urls']."$value</a></td>";

														  # 일본 제로 금리때문에 기호가 잘못 나오는 경우가 있음
																	 if(strpos($disp_hk_Data[1][$key][3],"+-")) $disp_hk_Data[1][$key][3]="";
																     if(strpos($disp_hk_Data[1][$key][4],"+-")) $disp_hk_Data[1][$key][4]="";
																	 if(strpos($disp_hk_Data[1][$key][5],"+-")) $disp_hk_Data[1][$key][5]="";

														$world_bond_Rate_tag.= "  <td style='font-size:12px;'>".$disp_hk_Data[0][$key][1]."</td>
																										<td>".$disp_hk_Data[0][$key][2]."</td> 
																										<td>".deco_txt($disp_hk_Data[0][$key][3],12,0)."</td>  
																										<td nowrap>".deco_txt($disp_hk_Data[0][$key][4],13,0)."</td>
																										<td  style='font-size:12px;' >".deco_txt($disp_hk_Data[1][$key][3],13,0)."</td>
																										<td style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][4],13,0)."</td>
																										<td style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][5],13,0)."</td>
																										<td style='font-size:11px;'>".$disp_hk_Data[0][$key][8]."</td>																		
																								   ";

														$world_bond_Rate_tag.="</tr>";

												}

					  $world_bond_Rate_tag.="</table></div>";




   # 원자재

      $hk_no=3;

						$world_res_tag="<div id='hk_data'><table border=0 cellpadding=\"5\" cellspacing=\"3\" style=\"border-collapse: collapse;width:540pt\">
						                                         <tr><Td colspan=5>$hk_data_Title[$hk_no]</td></tr>
																 <tr class=tt7 align=center><td colspan=2 width='210'>상품명 </td><td>종가</td><td>전일비</td><td>%</td><td>1개월</td><td>6개월</td><td>1년</td><td>시간</td></tr>";

												foreach($hk_in_array[$hk_no] as $key=>$value) {


													$disp_hk_Data=$Get_Hk_Data_ex_rate[$hk_no];

														$world_res_tag.="<tr class=tt8 height=\"40\" style='font-size:14px;'><td  style='font-size:13px;'>".$disp_hk_Data[0][$key]['urls']."$value</td>";

															   ## 환율 기호

															   																if($disp_hk_Data[0][$key][4]<0) $disp_hk_Data[0][$key][3]= $disp_hk_Data[0][$key][3]*(-1);


																		$world_res_tag.= "  <td style='font-size:12px;'>".$disp_hk_Data[0][$key][1]."</td>
																										<td>".$disp_hk_Data[0][$key][2]."</td> 
																										<td>".deco_txt($disp_hk_Data[0][$key][3],12,0)."</td>  
																										<td nowrap>".deco_txt($disp_hk_Data[0][$key][4],13,0)."</td>
																										<td  style='font-size:12px;' >".deco_txt($disp_hk_Data[1][$key][3],13,0)."</td>
																										<td style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][4],13,0)."</td>
																										<td style='font-size:12px;'>".deco_txt($disp_hk_Data[1][$key][5],13,0)."</td>
																										<td style='font-size:11px;'>".$disp_hk_Data[0][$key][8]."</td>																		
																								   ";

														$world_res_tag.="</tr>";

												}

					  $world_res_tag.="</table></div>";





	echo "<html><body>";



echo "

<script type=\"text/javascript\">
                                          

  function copyToClipboard(content_layer) {

     
         const link = 'https://webisfree.com';
  const title = '웹이즈프리';
  const copyEle = document.createElement('div');
  copyEle.innerHTML = `<a href=\"${link}\">${title}</a>`;

  document.body.appendChild(copyEle);

  const range = document.createRange();
  range.selectNode(copyEle);
  window.getSelection().removeAllRanges();
  window.getSelection().addRange(range);

  document.execCommand('copy');
  document.body.removeChild(copyEle);


        }


    	</script>    			

";




echo $style_css;



echo $tuja_juche_tags;



echo "<Br><br><br>";
echo $stock_money_flow_tags;

echo "<a onclick=\"copyToClipboard('content_layer')\"  style='cursor:hand'>aaaa</a>";

echo "<div id=\"content_layer\">";
echo "<Br><br><br>";
 # 2세계주요지수
echo $world_stock_index_tag;

echo "</div>";
echo "<Br><br><br>";
# 3주요 환율
echo  $world_ex_Rate_tag;


echo "<Br><br><br>";
# 4. 주요국 채권금리
echo $world_bond_Rate_tag;


echo "<Br><br><br>";
# 5. 원자재
echo $world_res_tag;


echo "<Br>";
echo "<Br>";

echo "</body></html>";
exit;







# 네이버, 자금동향




 ################### end of  Finance_info_Write #######################
}
################### end of  Finance_info_Write #######################







?>