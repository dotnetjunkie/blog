---
title:	"Breaking changes in SmtpClient in .NET 4.0"
date:	2010-05-06
author: Steven van Deursen
tags:   [.NET General, C#]
draft:	false
---

### In .NET 4.0 the SmtpClient class now implements IDisposable. This is a breaking change what you should watch out for.

For .NET 4.0 the BCL team decided to pool SMTP connections, just as .NET already did with database connections. This of course means that the [SmtpClient](https://docs.microsoft.com/en-us/dotnet/api/system.net.mail.smtpclient) class should implement IDisposable, just as the SqlConnection does. When STMP connections are pooled, the overhead over establishing a new connection is lowered, which is a good thing. However, this is a breaking change. Migrating your code to .NET 4.0, without any changes, could lead to the same connection pool timeout exceptions as we're are used with database connections.

Perhaps there are more of these 'hidden jams' inside the new .NET 4.0 framework. So when migrating to .NET 4.0, it's wise to recompile your project and run FxCop over it. When your code isn't too complicated, FxCop will spot the places where you didn't dispose any disposable object. And you can already prepare your code like this:

{{< highlight csharp >}}
var client = new SmtpClient();

// Do not remove this using. In .NET 4.0
// SmtpClient implements IDisposable.
using (client as IDisposable)
{
    client.Send(message);
}
{{< / highlight >}}

## Comments
