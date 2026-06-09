
  function openclub(url,size){ 
						var size =size+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes'; 
						var n=open(url,'club_pop',size); 
						n.focus(); 						
						} 

  function openclub1(url,size){ 
						var size =size+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
						var n=open(url,'club_pop1',size); 
						n.focus(); 						

						} 

  function openclub2(url,size,name){ 

					 //  var popupX = event.screenX;  //마우스위치
                     //  var popupY = event.screenY;

		                 // var popupX = (window.screen.width / 2);
					   	//    var popupY= (window.screen.height / 2);

						var popupX=	Math.min(event.screenX,window.screen.width / 2)-20;
						var popupY=	Math.min(event.screenY,window.screen.height / 2)-30;					
																																		 
						 var size =size+',left='+popupX+',top='+popupY+'     '+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 


						//var size =size+'toolbar=0,status=no,menubar=no,scrollbars=yes,resizable=yes,location=yes'; 
						var n=open(url,name,size); 
						n.focus(); 						
						}
						
  function close_club(url){ 
                              
						 opener.location.reload();						 
   				                     self.close();    		
		               }							
							

  function opener_club(str){ 
                              
						 window.opener.location.href = str ;
		               }	

 function chr_addtag(str,field) { 
	                              field.value+=str; 
               			    }
							


function myconfirm (mystr,mystr2) {

                                   if(!mystr2) mystr2="맞습니까?";								    

										f=confirm(mystr2);

									   if (f) {   	window.location=mystr;								                       													      
									                return;
												 }                                                                                
                                 }


function myconfirm2 (mystr) {
										f=confirm("맞습니까?");

									   if (f) {  f2=confirm("정말 맞습니까?");
                                                   if(f2) {  f3=prompt("암호를 입력하시면 삭제가 완료됩니다.",'관리자암호'); 
												             if(f3=='bobos') window.location=mystr; else alert('암호를 확인해주시기 바랍니다');
												   }        
									                return;
												 }                                                                                
                                 }


function Make_JS_Cookie(cookie_name,cookie_val) {
 
 document.cookie =cookie_name+"="+cookie_val;

//var allcookies = document.cookie;
//alert(allcookies);

}


function clearField(field){
		if (field.value == field.defaultValue) {
			field.value = "";
		}
	}

	function checkField(field){
		if (field.value == "") {
			field.value = field.defaultValue;
		}
    }






function saveCurrentPos (objTextArea) {
   if (objTextArea.createTextRange) 
         objTextArea.currentPos = document.selection.createRange().duplicate();
}

function insertText (text,objTextArea) {
   if (objTextArea.createTextRange && objTextArea.currentPos) {
         var currentPos = objTextArea.currentPos;
         currentPos.text =
           currentPos.text.charAt(currentPos.text.length - 1) == ' ' ?
             text + ' ' : text;
   }
   else
         objTextArea.value  = text;
}

  	function chkBox(bool,val) { // 전체선택/해제            		
 
				var obj = document.getElementsByName(val); 
						 
				for (var i=0; i<obj.length; i++) obj[i].checked = bool; 
			} 



function copyclipboard(intext) {

  window.clipboardData.setData("Text", intext);
   return true;     
 }
