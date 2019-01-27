---
title:	"Protecting against Regex DOS attacks"
date:	2010-05-05
author: Steven van Deursen
tags:   [.NET General, C#, Security]
draft:	false
---

### Bryan Sullivan describes in the May issue of his MSDN article a denial of service attack that abuses regular expressions. As Bryan explains, a poorly written regex can bring your server to its knees.

Bryan demonstrates that even the simplest regular expressions can bring your server to its knees. Here are some examples of regular expressions that can easily cause this to happen:

{{< highlight text >}}
^(\d+)+$
^(\d+)*$
^(\d*)*$
^(\d+|\s+)*$
^(\d|\d\d)+$
^(\d|\d?)+$
{{< / highlight >}}

Read more about the causes and the cures [here](https://msdn.microsoft.com/nl-nl/magazine/ff646973%28en-us%29.aspx).

#### UPDATE 2012-06-04: .NET 4.5 contains a RegEx.Timeout property to specify a maximum duration for the regex.

## Comments