<?php


include "shd/simple_html_dom.php";


# div로 분류한다음에.. 실제 소스를 보고, 찾을것.. 개발자모드에서 나오는것과 다름.
<div class="Gx5Zad xpd EtOod pkphOe"> 
<a href="/url?q=https://www.biotimes.co.kr/news/articleView.html%3Fidxno%3D17141&amp;sa=U&amp;ved=2ahUKEwiFsbOjsNGIAxUwjVYBHUQnOwQQxfQBegQIARAC&amp;usg=AOvVaw1_aZrncyyDBDGUGyZ0yRdp" data-ved="2ahUKEwiFsbOjsNGIAxUwjVYBHUQnOwQQxfQBegQIARAC">

	<div class="egMi0 kCrYT">  1
		<div class="DnJfK"> 2

			<div class="j039Wc"> 3
				<h3 class="zBAuLc l97dzf"> 
					<div class="BNeawe vvjwJb AP7Wnd">[특징주] 엔젠바이오, 고성능 급성 골수성 백혈병 진단제품 출시로 상한가</div> 4
				</h3>
			</div> 3

			<div class="sCuL3"> 
				<div class="BNeawe UPmit AP7Wnd lRVwie">바이오타임즈</div>
			</div>
		</div>
	</div>
	
	
	<div class="kCrYT">
		<div class="lcJF1d Q6Xouf G6SP0b">
			<div style="width:120px;height:66px;position:static"><img class="h1hFNe" alt="" src="data:image/gif;base64,R0lGODlhAQABAIAAAP///////yH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" style="width:120px;height:66px" id="dimg_13" data-deferred="1"></div>
		</div>
		
		<div>
			<div class="BNeawe s3v9rd AP7Wnd">
				<div>
					<div class="BNeawe s3v9rd AP7Wnd">[바이오타임즈] NGS 정밀진단 플랫폼 기업 엔젠바이오(대표 최대출, 354200)의 주가가 상한가를 기록 중이다.20일 코스닥시장에서 엔젠바이오는 전...<br>
						<span class="r0bn4c rQMQod">7시간 전</span>
					</div>
				</div>
			</div>
		</div>
		
		<div class="rl7ilb"></div>
	</div></a>
	
</div>



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



exit;



?>