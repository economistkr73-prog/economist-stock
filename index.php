<html>

이코노미스트의 주식이야기..<br>
다시 시작합니다.<br>



<?php

#require "./env/connect.inc";
#require "./env/e.fnc";


#변수정의

#$mypims=Get_Post_Get_Value('mode');


# 카테고리 가지고옴
#$category=get_category("mypims",$opt,$connect);
#$cat_Title_img=$category['cat_img'][$cycle_value['cat']];

$sch_Width=2200;
$sch_Hgt=300;  
$day_td_width="13.8%";

$cal_left_Width=700;

 foreach ($_REQUEST as $key => $value) {

					   ${$key} = $value;
							
				  } # end of foreach

#변수정의




    
if($yx) $today = mktime(0,0,0,$mx,1,$yx);
   else $today=time();



$today_a=time();

$cur_unixtime=mktime(0,0,0,date('m',time()),date('d',time()),date('y',time()));
$cur_minute=date("H",time());



$mm=date("m",$today); 
$next_mm=$mm+1;                       # 다음달
$prv_mm=$mm-1;                       # 이전달

if(!$yx) $dd=date("d",$today);
$yy=date("y",$today);                                       # 올  해
$YY=date("Y",$today);    

$this_m_f_day = mktime(0,0,0,$mm,1,$yy);                    # 달의 첫날
$this_m_e_day = mktime(0,0,0,$next_mm,1,$yy)-1;             # 달의 마지막날

$this_m_f_w=date("w",$this_m_f_day);                        # 달의 첫날의 요일의 순번
$this_m_e_w=date("w",$this_m_e_day);                        # 달의 마지막 날의 요일의 순번

$start_diary_day=$this_m_f_day-($this_m_f_w*3600*24);                 # 달력의 첫날
$end_diary_day  =$this_m_e_day+((6-$this_m_e_w)*3600*24);                 # 달력의 마지막날

$end_diary_day_moon=$end_diary_day+1;

$period_week=intval(($end_diary_day-$start_diary_day)/(3600*24*7))+1;  # 총 몇주가 있는가?

$start_day_w=date("m/d",$start_diary_day);
$end_day_w  =date("m/d",  $end_diary_day);


# 음력 가지고 오는 부분
 $lunar=lunar($start_diary_day,$end_diary_day_moon,0,$connect);
# 음력 가지고 오는 부분




echo "<HTML lang='ko'>
      <HEAD>              
      <meta charset='utf-8'>

		  <link rel=\"stylesheet\" href=\"style/economist.css\">
		  <script language=\"javascript\" src=\"style/economist.js\"></script>

	  </head>

	  <BODY leftmargin=10 topmargin=35 marginwidth=\"10\" marginheight=\"10\" bgcolor=\"#999999\" bgproperties=\"FIXED\" background=$bg_img>
	  	  
	  <table width=2800 align=\"center\" border=0 cellspacing=0 cellpadding=4 bgcolor=white style=\"filter:Alpha(Opacity=40)\" >

     ";


   echo "<tr>";

      echo "<td colspan=2 align=center>";  # 여분

     echo" <table>
	              <tr><td align=center><a href='index.php'><font style='font-size:40px;color:black;font-family:휴먼편지체'>".$yy."년 ".$mm."월</a></td></tr>
	              <tr><td style='font-size:14px;;font-family:휴먼편지체' align=center><img src='../img/ic/up_arr.gif'> <a href='index.php?yx=$yy&mx=$prv_mm'>이전달</a> 
				   &nbsp;  &nbsp; <img src='../img/ic/bul_dn_blue2.gif'> <a href='index.php?yx=$yy&mx=$next_mm'> 다음달</a></td></tr>
			</table>";

	  echo "</td>";


   echo "<td colspan=2>";

       require "top_hdr.php";  # 인덱스 상단.. 프로젝트 리스트 및 기타 정보

   echo "</td>";

   echo "</tr>";




   echo "<tr>";

   echo "<td>";
      
   echo"</td>";



   echo "<td valign=top width=$cal_left_Width >";
       require "left_hdr.php";
   echo"</td>";


   echo "<td width=$sch_Width align=center >";

     require "monthly.php";
  
   echo"</td>";

   echo "<td width=10 align=center>";
  
   echo"</td>";

echo "</tr>";


  echo "<tr><td colspan=2 align=center style='font-size:9pt;'>";

  echo "<table style='font-size:9pt;'><tr>";
  echo "<td><img src='img/ic/ic_c4.gif'> <a href=javascript:openclub2('env/mng.php?mode=cat_write&grp_name=mypims','width=500,height=950','prj')>카테고리 관리</a> &nbsp; ( ".$category['cat_list'].")</td>";
  echo "<td width=20></td>";
  echo "<td><img src='../img/ic/ic_meet.gif'> <a href=javascript:openclub2('../pims/mypims.php?mode=id_list&pims_no=".$value['pims_no']."','width=500,height=950','id_list')>인명록</a></td>";
  echo "</tr></table>";


   echo"</td></tr>";


echo "</table></body></html>";


?>