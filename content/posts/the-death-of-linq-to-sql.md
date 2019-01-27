---
title:	"The death of LINQ to SQL"
date:	2008-11-01
author: Steven van Deursen
tags:   [.NET General, Architecture, C#, Dependency Injection, ORM, Simple Injector, Security]
draft:	false
---

### The Microsoft ADO.NET team blog made an important announcement yesterday about the future of LINQ to SQL.

The ADO.NET team [announced](https://blogs.msdn.com/adonet/archive/2008/10/31/clarifying-the-message-on-l2s-futures.aspx) that Microsoft will continue to make some investments in LINQ to SQL, but they also made it pretty clear that LINQ to Entities is the recommended data access solution in the future frameworks. Microsoft will invest heavily in the Entity Framework.

I always wondered why Microsoft focused on two different O/RM technologies for the .NET framework. Some say LINQ to SQL was an intermediate solution. Fact is that LINQ to SQL was made by the C# team, instead of the ADO.NET team. It was of great importance for the C# team to release an O/RM mapper together with their new LINQ technology. Without a LINQ to databases implementation, the C# team would have a hard time evangelizing LINQ.

While LINQ to Entities is far more advanced than LINQ to SQL, the latter has some interesting features that LINQ to Entities lacks. Microsoft will add those missing features to the Entity Framework in .NET 4.0. In the meantime, I still see LINQ to SQL as a valuable solution for smaller projects or less experienced development teams. The bar for LINQ to Entities is currently too high for them.

There seems to be a [discussion](https://weblogs.asp.net/fredriknormen/archive/2008/11/01/bring-linq-to-sql-down.aspx) about whether LINQ to SQL should be removed from the framework. Don't be alarmed, because this won't happen. Microsoft would never break the backwards compatibility in such a severe way.

## Comments