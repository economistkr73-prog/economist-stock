<?php

echo "  
	  <table width=100% align=\"center\" border=0 cellspacing=0 cellpadding=4 bgcolor=white >
	  
	  ";



echo ("              
        <tr align=center>        
	          <td  class=tt4 height=30 align=center>    $mm 월
         </td>

        <td width=$day_td_width class=tt4><font size=3>일</td>        
        <td width=$day_td_width class=tt4><font size=3>월</td>        
        
        <td width=$day_td_width class=tt4><font size=3> 화        </td>        
        <td width=$day_td_width class=tt4><font size=3> 수        </td>        
        <td width=$day_td_width class=tt4><font size=3> 목        </td>        
        <td width=$day_td_width class=tt4><font size=3> 금        </td>        
        <td width=$day_td_width class=tt4><font size=3 color=blue>토 </font>       
			</td>
        
        </tr>        
      ");


for($x=0;$x<$period_week;$x++) {  # $x 몇번째 주인지를 나타내는 변수 start of for loop 1

     $xplus=$x+1;   

	 $start_line=$start_diary_day+($x*3600*24*7);
     $end_line=$start_line+(3600*24*7);


 echo " <tr align=center>";
      
    for($n=0;$n<7;$n++) { # start of for 002  일주일간의 데이터를 보여주는 부분

           $diary_time=$start_line+$n*3600*24;  		

	       $this_day[$n]="<a href='pims/mypims.php?mode=write&unixtime=$diary_time'>".date("d",$diary_time)."</a>";
		   $lunar_d[$n]=date("d",$lunar['lunar'][$diary_time]);

		   if(is_string($Display_Day_Title[$diary_time])) { $Display_Day_Tags[$n]= $Display_Day_Title[$diary_time];  }
		   else { $Display_Day_Tags[$n]=""; }
		   
 
		  if($lunar_d[$n]=='1' || $lunar_d[$n]=='15') $lunar_day[$n]="</a> (".date("n.d",$lunar['lunar'][$diary_time]).")"; 
		                                        else  $lunar_day[$n]="</a> ";


                      # 이번달안에 속해 있다면 테이블의 색깔을 분홍색으로 
					   # $img_t_memo_s="<img src=img/bul59.gif border=0>";

					  if($diary_time>=$this_m_f_day && $diary_time<$this_m_e_day) 
					         {  $high[$n]='tt7 style=color:balck'; 
                              
								if($diary_time==$cur_unixtime){ $this_day[$n]="<b>오늘</b>($this_day[$n])<a name=today>"; 
								$high[$n]='tt4  style=color:red';								
								} 

						     } else { $high[$n]="'' style='color:gray'"; 
           							#  $Display_Day_Tags[$n]="";
							          }
                      
					  # 이번달안에 속해 있다면 테이블의 색깔을 분홍색으로 



	}


            echo ("
						       <tr style='font-size:9pt;line-height:190%'>     
								   <td class=tt4 height=$sch_Hgt align=center> <font size=2><b>$xplus 주</b> </font>  </td>
								   <td class=$high[0] height=$sch_Hgt  valign=top>$this_day[0]  $lunar_day[0] <br>". $Display_Day_Tags[0]."</td>     <!--일-->
								   <td class=$high[1] height=$sch_Hgt  valign=top>$this_day[1] $lunar_day[1] <br>". $Display_Day_Tags[1]."</td>     <!--월-->
								   <td class=$high[2] height=$sch_Hgt  valign=top>$this_day[2] $lunar_day[2] <br>". $Display_Day_Tags[2]."</td>     <!--화-->
								   <td class=$high[3] height=$sch_Hgt  valign=top>$this_day[3] $lunar_day[3] <br>". $Display_Day_Tags[3]."</td>     <!--수-->
								   <td class=$high[4] height=$sch_Hgt  valign=top>$this_day[4] $lunar_day[4] <br>". $Display_Day_Tags[4]."</td>     <!--목-->
								   <td class=$high[5] height=$sch_Hgt  valign=top>$this_day[5] $lunar_day[5] <br>". $Display_Day_Tags[5]."</td>     <!--금-->
								   <td class=$high[6] height=$sch_Hgt  valign=top>$this_day[6] $lunar_day[6] <br>". $Display_Day_Tags[6]."</td>     <!--토-->
						       </tr>
                            ");
                       



}






echo "</table>";


?>